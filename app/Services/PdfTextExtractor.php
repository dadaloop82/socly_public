<?php

declare(strict_types=1);

namespace Socly\Services;

/**
 * Extract plain text from PDF (native text layer, then optional OCR via poppler+tesseract).
 */
final class PdfTextExtractor
{
    private const MIN_USEFUL_CHARS = 180;

    /**
     * @param array{
     *   ocr?:bool,
     *   max_pages?:int,
     *   max_seconds?:int,
     *   dpi?:int,
     *   lang?:string,
     *   on_progress?:callable(int,int):void
     * } $options
     * @return array{ok:bool,text:string,method:string,pages:int,error?:string}
     */
    public function extract(string $pdfPath, array $options = []): array
    {
        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            return ['ok' => false, 'text' => '', 'method' => '', 'pages' => 0, 'error' => 'missing'];
        }

        $native = $this->extractNative($pdfPath);
        if ($this->isUseful($native['text'])) {
            return [
                'ok' => true,
                'text' => $this->normalizeText($native['text']),
                'method' => 'pdftotext',
                'pages' => $native['pages'],
            ];
        }

        if (empty($options['ocr'])) {
            return [
                'ok' => false,
                'text' => $this->normalizeText($native['text']),
                'method' => 'pdftotext',
                'pages' => $native['pages'],
                'error' => 'no_text_layer',
            ];
        }

        return $this->extractViaOcr($pdfPath, $options);
    }

    /** Fast path: native text only. */
    public function extractQuick(string $pdfPath): array
    {
        return $this->extract($pdfPath, ['ocr' => false]);
    }

    public function isAvailable(): bool
    {
        return $this->bin('pdftotext') !== null;
    }

    public function ocrAvailable(): bool
    {
        return $this->bin('pdftoppm') !== null && $this->bin('tesseract') !== null;
    }

    /** @return array{text:string,pages:int} */
    private function extractNative(string $pdfPath): array
    {
        $bin = $this->bin('pdftotext');
        if ($bin === null) {
            return ['text' => '', 'pages' => $this->pageCount($pdfPath)];
        }
        $cmd = sprintf(
            '%s -enc UTF-8 %s - 2>/dev/null',
            escapeshellarg($bin),
            escapeshellarg($pdfPath)
        );
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $text = implode("\n", $out);
        return [
            'text' => $text,
            'pages' => $this->pageCount($pdfPath),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{ok:bool,text:string,method:string,pages:int,error?:string}
     */
    private function extractViaOcr(string $pdfPath, array $options): array
    {
        if (!$this->ocrAvailable()) {
            return ['ok' => false, 'text' => '', 'method' => '', 'pages' => 0, 'error' => 'ocr_unavailable'];
        }

        $pages = $this->pageCount($pdfPath);
        if ($pages < 1) {
            $pages = 1;
        }
        $maxPages = max(1, (int) ($options['max_pages'] ?? 20));
        $maxSeconds = max(30, (int) ($options['max_seconds'] ?? 300));
        $dpi = max(72, min(200, (int) ($options['dpi'] ?? 150)));
        $lang = trim((string) ($options['lang'] ?? 'ita'));
        if ($lang === '') {
            $lang = 'ita';
        }
        /** @var callable(int,int):void|null $onProgress */
        $onProgress = isset($options['on_progress']) && is_callable($options['on_progress'])
            ? $options['on_progress']
            : null;

        $pdftoppm = $this->bin('pdftoppm');
        $tesseract = $this->bin('tesseract');
        $tmpDir = sys_get_temp_dir() . '/socly-pdf-ocr-' . bin2hex(random_bytes(6));
        if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            return ['ok' => false, 'text' => '', 'method' => '', 'pages' => $pages, 'error' => 'tmpdir'];
        }

        $chunks = [];
        $started = microtime(true);
        $limit = min($pages, $maxPages);
        try {
            for ($page = 1; $page <= $limit; $page++) {
                if ((microtime(true) - $started) >= $maxSeconds) {
                    break;
                }
                if ($onProgress) {
                    $onProgress($page, $limit);
                }

                $prefix = $tmpDir . '/p' . $page;
                $render = sprintf(
                    '%s -f %d -l %d -r %d -png %s %s 2>/dev/null',
                    escapeshellarg((string) $pdftoppm),
                    $page,
                    $page,
                    $dpi,
                    escapeshellarg($pdfPath),
                    escapeshellarg($prefix)
                );
                exec($render, $ignored, $code);
                $png = $prefix . '-1.png';
                if (!is_file($png)) {
                    // some poppler builds use -01
                    $alt = $prefix . '-01.png';
                    $png = is_file($alt) ? $alt : '';
                }
                if ($png === '' || !is_file($png)) {
                    continue;
                }

                $ocrCmd = sprintf(
                    '%s %s stdout -l %s --psm 6 2>/dev/null',
                    escapeshellarg((string) $tesseract),
                    escapeshellarg($png),
                    escapeshellarg($lang)
                );
                $lines = [];
                exec($ocrCmd, $lines, $ocrCode);
                @unlink($png);
                $pageText = trim(implode("\n", $lines));
                if ($pageText !== '') {
                    $chunks[] = $pageText;
                }
            }
        } finally {
            $this->wipeDir($tmpDir);
        }

        $text = $this->normalizeText(implode("\n\n", $chunks));
        if (!$this->isUseful($text)) {
            return [
                'ok' => false,
                'text' => $text,
                'method' => 'ocr',
                'pages' => $pages,
                'error' => 'ocr_empty',
            ];
        }

        return [
            'ok' => true,
            'text' => $text,
            'method' => 'ocr',
            'pages' => $pages,
        ];
    }

    private function pageCount(string $pdfPath): int
    {
        $bin = $this->bin('pdfinfo');
        if ($bin === null) {
            return 0;
        }
        $cmd = sprintf('%s %s 2>/dev/null', escapeshellarg($bin), escapeshellarg($pdfPath));
        $out = [];
        exec($cmd, $out);
        foreach ($out as $line) {
            if (preg_match('/^Pages:\s*(\d+)/i', $line, $m)) {
                return max(0, (int) $m[1]);
            }
        }
        return 0;
    }

    private function isUseful(string $text): bool
    {
        $compact = preg_replace('/\s+/u', '', $text) ?? '';
        return mb_strlen($compact) >= self::MIN_USEFUL_CHARS;
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
        // Drop isolated form-feed noise from empty OCR pages.
        $text = str_replace("\f", "\n", $text);
        return trim($text);
    }

    private function bin(string $name): ?string
    {
        static $cache = [];
        if (array_key_exists($name, $cache)) {
            return $cache[$name];
        }
        $path = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        $cache[$name] = $path !== '' ? $path : null;
        return $cache[$name];
    }

    private function wipeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
