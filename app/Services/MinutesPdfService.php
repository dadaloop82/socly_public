<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */

declare(strict_types=1);

namespace Socly\Services;

/**
 * Minimal PDF writer for official association minutes.
 *
 * SOCLY ships no PDF library: this class writes a valid PDF 1.4 document with the
 * built-in Helvetica fonts, a branded header on every page, “Pagina X di Y” footers
 * (required for numbered minute books) and signature lines for president/secretary.
 */
final class MinutesPdfService
{
    private const PAGE_W = 595.28;
    private const PAGE_H = 841.89;
    private const MARGIN = 56.0;
    private const HEADER_H = 96.0;
    private const FOOTER_Y = 42.0;

    /** @var list<string> Content streams, one per page. */
    private array $pages = [];
    private string $content = '';
    private float $y = 0.0;
    private int $pageCount = 0;
    private string $documentTitle = '';
    private string $association = '';
    private string $associationMeta = '';
    private string $statusLabel = '';
    private string $watermarkLabel = '';

    /** @var array{r:int,g:int,b:int} */
    private array $brand = ['r' => 13, 'g' => 110, 'b' => 102];

    /** @var array{bytes:string,filter:string,w:int,h:int}|array{} */
    private array $logo = [];

    /**
     * @param array{
     *   title:string,association:string,status_label:string,brand:array{r:int,g:int,b:int},
     *   meta:list<array{label:string,value:string}>,
     *   sections:list<array{title:string,lines:list<string>}>,
     *   closing:string,
     *   signatures:list<array{role:string,name:string}>,
     *   watermark?:string,logo_path?:string
     * } $doc
     */
    public function render(array $doc): string
    {
        $this->pages = [];
        $this->content = '';
        $this->pageCount = 0;
        $this->documentTitle = (string) ($doc['title'] ?? '');
        $this->association = (string) ($doc['association'] ?? '');
        $this->associationMeta = (string) ($doc['association_meta'] ?? '');
        $this->statusLabel = (string) ($doc['status_label'] ?? '');
        $this->watermarkLabel = (string) ($doc['watermark'] ?? '');
        $this->logo = $this->prepareLogo(isset($doc['logo_path']) ? (string) $doc['logo_path'] : '');
        $brand = is_array($doc['brand'] ?? null) ? $doc['brand'] : [];
        $this->brand = [
            'r' => max(0, min(255, (int) ($brand['r'] ?? 13))),
            'g' => max(0, min(255, (int) ($brand['g'] ?? 110))),
            'b' => max(0, min(255, (int) ($brand['b'] ?? 102))),
        ];

        $this->startPage();

        $meta = is_array($doc['meta'] ?? null) ? $doc['meta'] : [];
        if ($meta !== []) {
            foreach ($meta as $row) {
                $label = trim((string) ($row['label'] ?? ''));
                $value = trim((string) ($row['value'] ?? ''));
                if ($label === '' && $value === '') {
                    continue;
                }
                $this->line($label !== '' ? $label . ': ' . $value : $value, 9.5, false, 0, 4);
            }
            $this->space(10);
            $this->rule();
            $this->space(10);
        }

        foreach ((array) ($doc['sections'] ?? []) as $section) {
            $title = trim((string) ($section['title'] ?? ''));
            $lines = is_array($section['lines'] ?? null) ? $section['lines'] : [];
            if ($title === '' && $lines === []) {
                continue;
            }
            if ($title !== '') {
                $this->ensureSpace(30);
                $this->line($title, 11.5, true, 0, 6);
            }
            foreach ($lines as $text) {
                $text = (string) $text;
                if (trim($text) === '') {
                    $this->space(6);
                    continue;
                }
                $this->line($text, 10, false, 0, 3);
            }
            $this->space(10);
        }

        $closing = trim((string) ($doc['closing'] ?? ''));
        if ($closing !== '') {
            $this->ensureSpace(30);
            $this->line($closing, 10, false, 0, 4);
        }

        $signatures = is_array($doc['signatures'] ?? null) ? $doc['signatures'] : [];
        if ($signatures !== []) {
            $this->ensureSpace(118);
            $this->space(42);
            $this->signatures($signatures);
        }

        $this->pages[] = $this->content;
        $total = count($this->pages);
        foreach ($this->pages as $index => $stream) {
            $prefix = $this->footerStream($index + 1, $total);
            if ($this->watermarkLabel !== '') {
                $prefix = $this->watermark() . $prefix;
            }
            $this->pages[$index] = $prefix . $stream;
        }

        return $this->buildPdf($this->pages);
    }

    private function startPage(): void
    {
        if ($this->pageCount > 0) {
            $this->pages[] = $this->content;
        }
        $this->pageCount++;
        $this->content = '';
        $this->header();
        $this->y = self::PAGE_H - self::HEADER_H - 42;
    }

    private function header(): void
    {
        $r = $this->brand['r'] / 255;
        $g = $this->brand['g'] / 255;
        $b = $this->brand['b'] / 255;
        $this->content .= sprintf(
            "q %.3F %.3F %.3F rg 0 %.2F %.2F %.2F re f Q\n",
            $r,
            $g,
            $b,
            self::PAGE_H - self::HEADER_H,
            self::PAGE_W,
            self::HEADER_H
        );
        if ($this->association !== '') {
            $nameWidth = $this->logo !== [] ? self::PAGE_W - (self::MARGIN * 2) - 160 : self::PAGE_W - (self::MARGIN * 2);
            $this->textAt(self::MARGIN, self::PAGE_H - 46, $this->fit($this->association, 15, $nameWidth), 15, true, [1, 1, 1]);
        }
        $titleY = self::PAGE_H - 68;
        if ($this->associationMeta !== '') {
            $metaWidth = $this->logo !== [] ? self::PAGE_W - (self::MARGIN * 2) - 160 : self::PAGE_W - (self::MARGIN * 2);
            $this->textAt(self::MARGIN, self::PAGE_H - 60, $this->fit($this->associationMeta, 8, $metaWidth), 8, false, [1, 1, 1]);
            $titleY = self::PAGE_H - 80;
        }
        $subtitle = $this->documentTitle !== '' ? $this->documentTitle : 'SOCLY';
        // The status label sits on the right: never let the title run under it.
        $this->textAt(self::MARGIN, $titleY, $this->fit($subtitle, 10, self::PAGE_W - (self::MARGIN * 2) - 120), 10, false, [1, 1, 1]);
        if ($this->logo !== []) {
            $maxW = 150.0;
            $maxH = 42.0;
            $ratio = min($maxW / max(1, $this->logo['w']), $maxH / max(1, $this->logo['h']));
            $logoW = $this->logo['w'] * $ratio;
            $logoH = $this->logo['h'] * $ratio;
            $logoX = self::PAGE_W - self::MARGIN - $logoW;
            $logoY = self::PAGE_H - 38 - $logoH;
            $this->content .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Logo Do Q\n", $logoW, $logoH, $logoX, $logoY);
        }
        if ($this->statusLabel !== '') {
            $statusX = self::PAGE_W - self::MARGIN - 70;
            $statusY = $this->logo !== [] ? self::PAGE_H - 92 : self::PAGE_H - 46;
            $this->textAt($statusX, $statusY, $this->statusLabel, 9, true, [1, 1, 1]);
        }
    }

    private function footerStream(int $page, int $total): string
    {
        $label = $this->escape(__('documents.minutes_pdf_page', [
            'page' => (string) $page,
            'total' => (string) $total,
        ]));
        $x = self::PAGE_W / 2 - 32;

        return "BT /F1 8 Tf 0.35 0.40 0.39 rg 1 0 0 1 {$x} " . self::FOOTER_Y . " Tm ({$label}) Tj ET\n";
    }

    private function watermark(): string
    {
        $label = $this->escape($this->watermarkLabel);

        return sprintf(
            "q BT /F2 62 Tf 0.82 0.84 0.83 rg 0.7071 0.7071 -0.7071 0.7071 118 330 Tm (%s) Tj ET Q\n",
            $label
        );
    }

    /**
     * @param list<array{role:string,name:string}> $signatures
     */
    private function signatures(array $signatures): void
    {
        $width = 200.0;
        $gap = 60.0;
        $left = self::MARGIN;
        $y = $this->y;
        foreach (array_slice($signatures, 0, 2) as $index => $signature) {
            $x = $left + $index * ($width + $gap);
            $role = trim((string) ($signature['role'] ?? ''));
            $name = trim((string) ($signature['name'] ?? ''));
            $this->textAt($x, $y + 22, $this->fit($role, 9, $width), 9, false, [0.35, 0.40, 0.39]);
            $this->content .= sprintf(
                "0.45 0.48 0.47 RG 0.8 w %.2F %.2F m %.2F %.2F l S\n",
                $x,
                $y,
                $x + $width,
                $y
            );
            if ($name !== '') {
                $this->textAt($x, $y - 16, $this->fit($name, 10, $width), 10, true, [0.10, 0.17, 0.16]);
            }
        }
        $this->y = $y - 30;
    }

    private function rule(): void
    {
        $this->content .= sprintf(
            "0.85 0.87 0.86 RG 0.6 w %.2F %.2F m %.2F %.2F l S\n",
            self::MARGIN,
            $this->y,
            self::PAGE_W - self::MARGIN,
            $this->y
        );
    }

    private function space(float $height): void
    {
        $this->y -= $height;
        if ($this->y < self::FOOTER_Y + 42) {
            $this->startPage();
        }
    }

    private function ensureSpace(float $height): void
    {
        if ($this->y - $height < self::FOOTER_Y + 42) {
            $this->startPage();
        }
    }


    private function line(string $text, float $size = 10, bool $bold = false, float $indent = 0, float $after = 3): void
    {
        $maxWidth = self::PAGE_W - (self::MARGIN * 2) - $indent;
        foreach ($this->wrap($text, $size, $maxWidth) as $chunk) {
            $this->ensureSpace($size + 4);
            $this->textAt(self::MARGIN + $indent, $this->y, $chunk, $size, $bold, [0.06, 0.15, 0.14]);
            $this->y -= $size + 4.2;
        }
        $this->y -= $after;
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, float $size, float $maxWidth): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return [''];
        }
        $words = explode(' ', $text);
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($this->textWidth($candidate, $size) <= $maxWidth) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }
            // Hard-split an overlong word so it can never overflow the page.
            while (mb_strlen($word) > 1 && $this->textWidth($word, $size) > $maxWidth) {
                $ratio = $maxWidth / max(1.0, $this->textWidth($word, $size));
                $cut = max(1, (int) floor(mb_strlen($word) * $ratio));
                $lines[] = mb_substr($word, 0, $cut);
                $word = mb_substr($word, $cut);
            }
            $current = $word;
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    /** Approximate Helvetica advance width (good enough for wrapping). */
    private function textWidth(string $text, float $size): float
    {
        $chars = mb_strlen($text);
        $narrow = preg_match_all('/[il.,;:\'!|]/u', $text) ?: 0;

        return max(0.0, ($chars * 0.53 - $narrow * 0.13) * $size);
    }

    /**
     * Cut a single-line text to the available width. Two signature columns sit side by side: an
     * overlong name (“Mario Rossi — Presidente (legale rappresentante)”) used to run into the
     * next column and print on top of it.
     */
    private function fit(string $text, float $size, float $width): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '' || $width <= 0 || $this->textWidth($text, $size) <= $width) {
            return $text;
        }
        $cut = $text;
        while ($cut !== '' && $this->textWidth($cut . '...', $size) > $width) {
            $cut = mb_substr($cut, 0, max(0, mb_strlen($cut) - 1));
        }

        return $cut === '' ? '' : rtrim($cut) . '...';
    }

    /**
     * @param array{0:float|int,1:float|int,2:float|int} $rgb
     */
    private function textAt(float $x, float $y, string $text, float $size, bool $bold, array $rgb): void
    {
        $font = $bold ? 'F2' : 'F1';
        $color = sprintf('%.3F %.3F %.3F', $rgb[0], $rgb[1], $rgb[2]);
        $this->content .= sprintf(
            "BT /%s %.2F Tf %s rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $font,
            $size,
            $color,
            $x,
            $y,
            $this->escape($text)
        );
    }

    private function escape(string $text): string
    {
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $text);
        if (!is_string($converted) || $converted === '') {
            $converted = preg_replace('/[^\x20-\x7E]/', ' ', $text) ?? $text;
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }

    /**
     * Loads the association logo for the PDF header. JPEG bytes are embedded as-is
     * (DCTDecode); PNG/WEBP/GIF are converted to raw RGB with GD when available.
     *
     * @return array{bytes:string,filter:string,w:int,h:int}|array{}
     */
    private function prepareLogo(string $relative): array
    {
        $relative = trim($relative);
        if ($relative === '') {
            return [];
        }
        $absolute = resolve_upload_absolute_path($relative);
        if ($absolute === null || !is_file($absolute)) {
            return [];
        }
        $info = @getimagesize($absolute);
        if (!is_array($info) || (int) ($info[0] ?? 0) < 1 || (int) ($info[1] ?? 0) < 1) {
            return [];
        }
        $mime = (string) ($info['mime'] ?? '');
        if ($mime === 'image/jpeg') {
            $bytes = @file_get_contents($absolute);
            if (!is_string($bytes) || $bytes === '') {
                return [];
            }

            return ['bytes' => $bytes, 'filter' => 'DCTDecode', 'w' => (int) $info[0], 'h' => (int) $info[1]];
        }
        $loader = match ($mime) {
            'image/png' => 'imagecreatefrompng',
            'image/webp' => 'imagecreatefromwebp',
            'image/gif' => 'imagecreatefromgif',
            default => '',
        };
        if ($loader === '' || !function_exists($loader)) {
            return [];
        }
        $image = @$loader($absolute);
        if (!$image instanceof \GdImage) {
            return [];
        }
        $width = (int) imagesx($image);
        $height = (int) imagesy($image);
        $scale = min(1.0, 480 / max(1, $width), 240 / max(1, $height));
        $targetW = max(1, (int) round($width * $scale));
        $targetH = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($targetW, $targetH);
        if (!$canvas instanceof \GdImage) {
            imagedestroy($image);

            return [];
        }
        // Transparent logos sit on the brand-coloured header: use white behind them.
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, $white);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
        imagedestroy($image);

        $raw = '';
        for ($y = 0; $y < $targetH; $y++) {
            for ($x = 0; $x < $targetW; $x++) {
                $rgb = imagecolorat($canvas, $x, $y);
                $raw .= chr(($rgb >> 16) & 0xFF) . chr(($rgb >> 8) & 0xFF) . chr($rgb & 0xFF);
            }
        }
        imagedestroy($canvas);

        return [
            'bytes' => gzcompress($raw, 6),
            'filter' => 'FlateDecode',
            'w' => $targetW,
            'h' => $targetH,
        ];
    }

    /**
     * @param list<string> $streams
     */
    private function buildPdf(array $streams): string
    {
        $streams = array_values($streams);
        $pageTotal = max(1, count($streams));
        $font1 = 3 + $pageTotal * 2;
        $font2 = $font1 + 1;
        $imageNo = $this->logo !== [] ? $font2 + 1 : 0;
        $xObject = $imageNo > 0 ? ' /XObject << /Logo ' . $imageNo . ' 0 R >>' : '';

        $kids = [];
        for ($i = 0; $i < $pageTotal; $i++) {
            $kids[] = (3 + $i * 2) . ' 0 R';
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageTotal . ' >>';
        for ($i = 0; $i < $pageTotal; $i++) {
            $pageNo = 3 + $i * 2;
            $contentNo = 4 + $i * 2;
            $stream = $streams[$i] ?? '';
            $objects[$pageNo] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89]'
                . ' /Resources << /Font << /F1 ' . $font1 . ' 0 R /F2 ' . $font2 . ' 0 R >>' . $xObject . ' >>'
                . ' /Contents ' . $contentNo . ' 0 R >>';
            $objects[$contentNo] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }
        $objects[$font1] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[$font2] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        if ($imageNo > 0) {
            $logoBytes = (string) $this->logo['bytes'];
            $objects[$imageNo] = '<< /Type /XObject /Subtype /Image'
                . ' /Width ' . (int) $this->logo['w'] . ' /Height ' . (int) $this->logo['h']
                . ' /ColorSpace /DeviceRGB /BitsPerComponent 8'
                . ' /Filter /' . (string) $this->logo['filter']
                . ' /Length ' . strlen($logoBytes) . " >>\nstream\n" . $logoBytes . "\nendstream";
        }
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        $count = count($objects);
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $total = $count + 1;
        $pdf .= "xref\n0 {$total}\n0000000000 65535 f \n";
        for ($i = 1; $i <= $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size {$total} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }
}
