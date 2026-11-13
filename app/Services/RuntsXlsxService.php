<?php

declare(strict_types=1);

namespace Socly\Services;

use RuntimeException;
use ZipArchive;

/**
 * Model D (cash flow statement, DM 18 aprile 2020) exported through the official
 * editable workbook (CSV Emilia / Ministry form), pre-filled with the amounts of the
 * approved cash book. The same row model feeds the on-screen document, so the sheet and
 * the XLSX are identical by construction.
 */
final class RuntsXlsxService
{
    public const TEMPLATE = '/resources/templates/mod-d.xlsx';

    /** @var array<int,string> official labels of the exit column, by sheet row */
    private const EXPENSE_LABELS = [
        11 => '1) Materie prime, sussidiarie, di consumo e di merci',
        12 => '2) Servizi',
        13 => '3) Godimento di beni di terzi',
        14 => '4) Personale',
        15 => '5) Uscite diverse di gestione',
        28 => '1) Materie prime, sussidiarie, di consumo e di merci',
        29 => '2) Servizi',
        30 => '3) Godimento di beni di terzi',
        31 => '4) Personale',
        32 => '5) Uscite diverse di gestione',
        41 => '1) Uscite per raccolte fondi abituali',
        42 => '2) Uscite per raccolte fondi occasionali',
        43 => '3) Altre uscite',
        50 => '1) Su rapporti bancari',
        51 => '2) Su investimenti finanziari',
        52 => '3) Su patrimonio edilizio',
        53 => '4) Su altri beni patrimoniali',
        54 => '5) Altre uscite',
        62 => '1) Materie prime, sussidiarie, di consumo e di merci',
        63 => '2) Servizi',
        64 => '3) Godimento di beni di terzi',
        65 => '4) Personale',
        66 => '5) Altre uscite',
        79 => '1) Investimenti in immobilizzazioni inerenti alle attività di interesse generale',
        80 => '2) Investimenti in immobilizzazioni inerenti alle attività diverse',
        81 => '3) Investimenti in attività finanziarie e patrimoniali',
        82 => '4) Rimborso di finanziamenti per quota capitale e di prestiti',
    ];

    /** @var array<int,string> official labels of the entry column, by sheet row */
    private const INCOME_LABELS = [
        11 => '1) Entrate da quote associative e apporti dei fondatori',
        12 => '2) Entrate dagli associati per attività mutualistiche',
        13 => '3) Entrate per prestazioni e cessioni ad associati e fondatori',
        14 => '4) Erogazioni liberali',
        15 => '5) Entrate del 5 per mille',
        16 => '6) Contributi da soggetti privati',
        17 => '7) Entrate per prestazioni e cessioni a terzi',
        18 => '8) Contributi da enti pubblici',
        19 => '9) Entrate da contratti con enti pubblici',
        20 => '10) Altre entrate',
        28 => '1) Entrate per prestazioni e cessioni ad associati e fondatori',
        29 => '2) Contributi da soggetti privati',
        30 => '3) Entrate per prestazioni e cessioni a terzi',
        31 => '4) Contributi da enti pubblici',
        32 => '5) Entrate da contratti con enti pubblici',
        33 => '6) Altre entrate',
        41 => '1) Entrate da raccolte fondi abituali',
        42 => '2) Entrate da raccolte fondi occasionali',
        43 => '3) Altre entrate',
        50 => '1) Da rapporti bancari',
        51 => '2) Da altri investimenti finanziari',
        52 => '3) Da patrimonio edilizio',
        53 => '4) Da altri beni patrimoniali',
        54 => '5) Altre entrate',
        62 => '1) Entrate da distacco del personale',
        63 => '2) Altre entrate di supporto generale',
        79 => '1) Disinvestimenti in immobilizzazioni inerenti alle attività di interesse generale',
        80 => '2) Disinvestimenti in immobilizzazioni inerenti alle attività diverse',
        81 => '3) Disinvestimenti in attività finanziarie e patrimoniali',
        82 => '4) Ricevimento di finanziamenti e di prestiti',
    ];

    public function __construct(
        private readonly string $basePath,
        private readonly RuntsReportService $runts
    ) {
    }

    /**
     * Fill the official workbook: header (ente + years), the items of A–E, totals, result,
     * investments and cash, for the current and the previous year.
     *
     * @param array<string,mixed> $report
     * @param array<string,mixed>|null $previous
     * @param array<string,mixed> $meta
     */
    public function build(int $year, array $report, ?array $previous, array $meta): string
    {
        $path = rtrim($this->basePath, '/') . self::TEMPLATE;
        if (!is_file($path)) {
            throw new RuntimeException('Template Modello D mancante.');
        }
        $cells = $this->values($report);
        if (is_array($previous)) {
            foreach ($this->values($previous) as $cell => $value) {
                $previousCell = preg_replace_callback(
                    '/^([BE])(.*)$/',
                    static fn (array $m): string => ($m[1] === 'B' ? 'C' : 'F') . $m[2],
                    (string) $cell
                );
                if (is_string($previousCell)) {
                    $cells[$previousCell] = $value;
                }
            }
        }
        $xml = '';
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
        }
        if ($xml === '') {
            throw new RuntimeException('Foglio Modello D mancante.');
        }
        $ente = trim((string) ($meta['ente'] ?? ''));
        if ($ente !== '') {
            $xml = $this->setString($xml, 'A1', 'ENTE DEL TERZO SETTORE "' . $ente . '"');
        }
        foreach (['B5' => $year, 'E5' => $year, 'C5' => $year - 1, 'F5' => $year - 1] as $cell => $value) {
            $xml = $this->setNumber($xml, $cell, (float) $value);
        }
        foreach ($cells as $cell => $value) {
            $xml = $this->setNumber($xml, (string) $cell, (float) $value);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'modd');
        if ($tmp === false || !copy($path, $tmp)) {
            if (is_string($tmp)) {
                @unlink($tmp);
            }
            throw new RuntimeException('Copia del modello non riuscita.');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Modello Modello D non apribile.');
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /**
     * Item values by cell (current year; the previous year reuses the map shifted to C/F).
     *
     * @param array<string,mixed> $report
     * @return array<string,float>
     */
    private function values(array $report): array
    {
        $sections = is_array($report['sections'] ?? null) ? $report['sections'] : [];
        $line = static function (string $letter, string $direction, string $key) use ($sections): float {
            return round((float) ($sections[$letter]['lines'][$direction][$key] ?? 0), 2);
        };
        $total = static function (string $letter, string $direction) use ($sections): float {
            return round((float) ($sections[$letter]['totals'][$direction] ?? 0), 2);
        };
        $expenseTotal = round((float) ($report['total_expense'] ?? 0), 2);
        $incomeTotal = round((float) ($report['total_income'] ?? 0), 2);
        $balance = round($incomeTotal - $expenseTotal, 2);
        $investmentExpense = round(
            $line('D', 'expense', 'D-E1') + $line('D', 'expense', 'D-E2') + $line('D', 'expense', 'D-E3'),
            2
        );
        $investmentBalance = round(-$investmentExpense, 2);
        $closing = is_array($report['prospect']['closing'] ?? null) ? $report['prospect']['closing'] : [];
        $opening = is_array($report['prospect']['opening'] ?? null) ? $report['prospect']['opening'] : [];
        $result = round($balance + $investmentBalance, 2);

        return [
            'B11' => $line('A', 'expense', 'A-E1'),
            'B12' => $line('A', 'expense', 'A-E2'),
            'B13' => $line('A', 'expense', 'A-E3'),
            'B14' => $line('A', 'expense', 'A-E4'),
            'B15' => $line('A', 'expense', 'A-E5'),
            'E11' => $line('A', 'income', 'A-I1'),
            'E14' => $line('A', 'income', 'A-I6'),
            'E15' => $line('A', 'income', 'A-I7'),
            'E16' => $line('A', 'income', 'A-I3'),
            'E17' => $line('A', 'income', 'A-I5'),
            'E18' => $line('A', 'income', 'A-I2'),
            'E19' => $line('A', 'income', 'A-I4'),
            'E20' => $line('A', 'income', 'A-I8'),
            'B22' => $total('A', 'expense'),
            'E22' => $total('A', 'income'),
            'E24' => round($total('A', 'income') - $total('A', 'expense'), 2),
            'B28' => $line('B', 'expense', 'B-E1'),
            'B29' => $line('B', 'expense', 'B-E2'),
            'B30' => $line('B', 'expense', 'B-E3'),
            'B31' => $line('B', 'expense', 'B-E4'),
            'B32' => $line('B', 'expense', 'B-E5'),
            'E29' => $line('B', 'income', 'B-I1'),
            'B36' => $total('B', 'expense'),
            'E36' => $total('B', 'income'),
            'E37' => round($total('B', 'income') - $total('B', 'expense'), 2),
            'B41' => $line('C', 'expense', 'C-E1'),
            'B42' => $line('C', 'expense', 'C-E2'),
            'E41' => $line('C', 'income', 'C-I1'),
            'E42' => $line('C', 'income', 'C-I2'),
            'B44' => $total('C', 'expense'),
            'E44' => $total('C', 'income'),
            'E46' => round($total('C', 'income') - $total('C', 'expense'), 2),
            'E50' => $line('D', 'income', 'D-I1'),
            'E52' => $line('D', 'income', 'D-I2'),
            'E54' => $line('D', 'income', 'D-I3'),
            'B56' => 0.0,
            'E56' => $total('D', 'income'),
            'E58' => $total('D', 'income'),
            'B62' => $line('E', 'expense', 'E-E1'),
            'B63' => $line('E', 'expense', 'E-E2'),
            'B64' => $line('E', 'expense', 'E-E3'),
            'B65' => $line('E', 'expense', 'E-E4'),
            'B66' => $line('E', 'expense', 'E-E5'),
            'E63' => $line('E', 'income', 'E-I1'),
            'B69' => $total('E', 'expense'),
            'E69' => $total('E', 'income'),
            'B71' => $expenseTotal,
            'E71' => $incomeTotal,
            'E72' => $balance,
            'E74' => 0.0,
            'E76' => $balance,
            'B79' => $line('D', 'expense', 'D-E1'),
            'B81' => $line('D', 'expense', 'D-E2'),
            'B82' => $line('D', 'expense', 'D-E3'),
            'B84' => $investmentExpense,
            'E84' => 0.0,
            'E86' => $investmentBalance,
            'E88' => 0.0,
            'E90' => $investmentBalance,
            'E92' => $balance,
            'E93' => $investmentBalance,
            'E94' => $result,
            'E96' => round((float) ($closing['cash'] ?? 0), 2),
            'E97' => round((float) ($closing['bank'] ?? 0), 2),
            'E98' => round((float) ($closing['total'] ?? 0), 2),
            'B106' => 0.0,
            'B107' => 0.0,
            'B108' => 0.0,
            'E106' => 0.0,
            'E107' => 0.0,
            'E108' => 0.0,
            'B111' => round((float) ($opening['total'] ?? 0), 2),
            'B112' => $result,
            'B113' => round((float) ($closing['total'] ?? 0), 2),
        ];
    }

    /** Replace (or create) a numeric cell, keeping its style (and its formula, when there is one). */
    private function setNumber(string $xml, string $cell, float $value): string
    {
        $number = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        if ($number === '' || $number === '-') {
            $number = '0';
        }

        return $this->writeCell(
            $xml,
            $cell,
            static function (string $attrs, string $inner) use ($cell, $number): string {
                // Totals in the official form are formulas (SUM…): the formula stays, only the cached
                // value is updated, so Excel recalculates on open without a broken calc chain.
                $formula = preg_match('~<f[^>]*>.*?</f>~s', $inner, $m) === 1 ? $m[0] : '';

                return '<c r="' . $cell . '"' . self::cellStyle($attrs) . '>' . $formula
                    . '<v>' . $number . '</v></c>';
            }
        );
    }

    /** Replace (or create) a text cell as inline string, keeping its style. */
    private function setString(string $xml, string $cell, string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return $this->writeCell(
            $xml,
            $cell,
            static fn (string $attrs, string $inner): string => '<c r="' . $cell . '"' . self::cellStyle($attrs)
                . ' t="inlineStr"><is><t xml:space="preserve">' . $escaped . '</t></is></c>'
        );
    }

    /**
     * Style/other attributes of an existing cell, without its value type: the template stores the
     * header years as shared strings, and keeping `t="s"` on a number makes Excel read the number as
     * an index into the shared-strings table (an empty cell).
     */
    private static function cellStyle(string $attrs): string
    {
        $clean = (string) preg_replace('~\s*t="[^"]*"~', '', $attrs);

        return rtrim($clean);
    }

    /**
     * Rewrite one cell, whether the template renders it as `<c …>…</c>` or as an empty `<c …/>`;
     * a cell the sheet does not have at all is inserted in the right row/column.
     *
     * @param callable(string,string):string $fragment builds the cell from attributes and content
     */
    private function writeCell(string $xml, string $cell, callable $fragment): string
    {
        $pattern = '~<c r="' . preg_quote($cell, '~') . '"(?P<attrs>[^>]*?)(?P<inner>/>|>.*?</c>)~s';
        $count = 0;
        $updated = preg_replace_callback(
            $pattern,
            static function (array $m) use ($fragment): string {
                $inner = (string) ($m['inner'] ?? '');
                $content = str_starts_with($inner, '/>') ? '' : substr($inner, 1, -4);

                return $fragment((string) ($m['attrs'] ?? ''), $content);
            },
            $xml,
            1,
            $count
        );
        if ($count > 0 && is_string($updated)) {
            return $updated;
        }

        return $this->insertCell($xml, $cell, $fragment('', ''));
    }

    /** Insert a cell in its row (ascending column order), creating the row when the sheet lacks it. */
    private function insertCell(string $xml, string $cell, string $fragment): string
    {
        $row = (int) preg_replace('/^[A-Z]+/', '', $cell);
        $column = (string) preg_replace('/\d+/', '', $cell);

        // 1. The row exists with content: place the cell before the first higher column.
        //    `(?<!/)>` keeps self-closing rows (`<row r="6"/>`) out of this branch.
        $pattern = '~(<row r="' . $row . '"[^>]*?(?<!/)>)(.*?)(</row>)~s';
        $count = 0;
        $updated = preg_replace_callback(
            $pattern,
            static function (array $m) use ($row, $column, $fragment): string {
                $cells = (string) $m[2];
                $position = null;
                if (preg_match_all('~<c r="([A-Z]+)' . $row . '"~', $cells, $matches, PREG_OFFSET_CAPTURE) > 0) {
                    foreach ($matches[0] as $index => $match) {
                        if (self::compareColumns((string) $matches[1][$index][0], $column) > 0) {
                            $position = (int) $match[1];
                            break;
                        }
                    }
                }
                if ($position === null) {
                    return $m[1] . $cells . $fragment . $m[3];
                }

                return $m[1] . substr($cells, 0, $position) . $fragment . substr($cells, $position) . $m[3];
            },
            $xml,
            1,
            $count
        );
        if ($count > 0 && is_string($updated)) {
            return $updated;
        }

        // 2. Empty row rendered as `<row …/>`: give it its first cell.
        $count = 0;
        $updated = preg_replace_callback(
            '~<row r="' . $row . '"([^>]*?)/>~s',
            static fn (array $m): string => '<row r="' . $row . '"' . $m[1] . '>' . $fragment . '</row>',
            $xml,
            1,
            $count
        );
        if ($count > 0 && is_string($updated)) {
            return $updated;
        }

        // 3. The row is missing: create it before the first higher row (the sheet must stay ordered).
        $newRow = '<row r="' . $row . '">' . $fragment . '</row>';
        if (preg_match_all('~<row r="(\d+)"~', $xml, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as $index => $match) {
                if ((int) $matches[1][$index][0] > $row) {
                    $position = (int) $match[1];

                    return substr($xml, 0, $position) . $newRow . substr($xml, $position);
                }
            }
        }
        $closing = strpos($xml, '</sheetData>');
        if ($closing !== false) {
            return substr($xml, 0, $closing) . $newRow . substr($xml, $closing);
        }

        return $xml;
    }

    /** Spreadsheet column order: A < Z < AA < AB (a longer reference is always greater). */
    private static function compareColumns(string $a, string $b): int
    {
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }

        return strcmp($a, $b) <=> 0;
    }
}
