<?php

declare(strict_types=1);

namespace Socly\Support;

/**
 * Streaming CSV reader for the members importer.
 *
 * Sniffs the delimiter and the encoding (UTF-8 / Windows-1252), strips the BOM,
 * skips empty rows and converts every cell to UTF-8. Deliberately dependency free:
 * the project has no CSV library and no build step.
 */
final class CsvReader
{
    /** Candidate delimiters, most frequent first when sniffing. */
    private const DELIMITERS = [';', ',', "\t", '|'];

    private readonly string $delimiter;
    private readonly string $encoding;

    public function __construct(private readonly string $path)
    {
        $this->delimiter = $this->sniffDelimiter();
        $this->encoding = $this->sniffEncoding();
    }

    public function path(): string
    {
        return $this->path;
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    public function encoding(): string
    {
        return $this->encoding;
    }

    /** Human readable delimiter for the UI (localized name of the character). */
    public function delimiterLabel(): string
    {
        return match ($this->delimiter) {
            ';' => ';',
            ',' => ',',
            "\t" => 'TAB',
            '|' => '|',
            default => $this->delimiter,
        };
    }

    /**
     * Read the whole file.
     *
     * @return list<array{line:int,cells:list<string>}>
     */
    public function rows(int $limit = 0): array
    {
        $handle = $this->open();
        if ($handle === null) {
            return [];
        }
        $rows = [];
        $line = 0;
        while (($cells = fgetcsv($handle, 0, $this->delimiter, '"', '\\')) !== false) {
            $line += 1;
            if ($cells === [] || $cells === [null]) {
                continue;
            }
            $normalized = [];
            foreach ($cells as $index => $cell) {
                $value = $this->toUtf8((string) $cell);
                if ($index === 0) {
                    $value = $this->stripBom($value);
                }
                $normalized[] = trim($value);
            }
            if ($this->isBlankRow($normalized)) {
                continue;
            }
            $rows[] = ['line' => $line, 'cells' => $normalized];
            if ($limit > 0 && count($rows) >= $limit) {
                break;
            }
        }
        fclose($handle);
        return $rows;
    }

    /**
     * First row looks like a header (labels, not data).
     *
     * Heuristic: a header row contains no "strong" data (dates, emails, tax codes,
     * amounts, phones) and is weaker than the data row below it.
     *
     * @param list<array{line:int,cells:list<string>}> $rows
     */
    public function hasHeader(array $rows): bool
    {
        if ($rows === []) {
            return false;
        }
        $first = $this->rowDataScore($rows[0]['cells']);
        if ($first === 0) {
            return true;
        }
        if (!isset($rows[1])) {
            return false;
        }
        return $this->rowDataScore($rows[1]['cells']) > $first;
    }

    /**
     * How "data-like" a row is: strong values (dates, emails, tax codes, amounts,
     * phones) weigh more than plain numbers; words and labels weigh nothing.
     *
     * @param list<string> $cells
     */
    private function rowDataScore(array $cells): int
    {
        $score = 0;
        foreach ($cells as $cell) {
            if (trim($cell) === '') {
                continue;
            }
            $score += match (ImportValueNormalizer::detectValueKind($cell)) {
                'date', 'datetime', 'email', 'fiscal_code', 'amount', 'phone' => 3,
                'number' => 1,
                default => 0,
            };
        }
        return $score;
    }

    /** @return resource|null */
    private function open()
    {
        $handle = fopen($this->path, 'rb');
        return $handle === false ? null : $handle;
    }

    private function sniffDelimiter(): string
    {
        $handle = $this->open();
        if ($handle === null) {
            return ';';
        }
        $sample = '';
        $lines = 0;
        while (!feof($handle) && $lines < 5) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $sample .= $line;
            $lines += 1;
        }
        fclose($handle);
        if ($sample === '') {
            return ';';
        }
        $best = ';';
        $bestScore = -1;
        foreach (self::DELIMITERS as $candidate) {
            $score = substr_count($sample, $candidate);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }
        return $best;
    }

    private function sniffEncoding(): string
    {
        $handle = $this->open();
        if ($handle === null) {
            return 'UTF-8';
        }
        $chunk = (string) fread($handle, 65536);
        fclose($handle);
        if (str_starts_with($chunk, "\xEF\xBB\xBF")) {
            return 'UTF-8';
        }
        return mb_check_encoding($chunk, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
    }

    private function toUtf8(string $value): string
    {
        if ($this->encoding === 'UTF-8' || $value === '') {
            return $value;
        }
        return mb_convert_encoding($value, 'UTF-8', $this->encoding);
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    /** @param list<string> $cells */
    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }
        return true;
    }
}
