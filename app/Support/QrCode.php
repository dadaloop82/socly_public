<?php

declare(strict_types=1);

namespace Socly\Support;

/**
 * QR code in SVG server side (no GD extension required).
 * The encoder is third-party (Kazuhiko Arase, MIT) and is vendored in
 * app/Support/QrCode/qrcode.php: here there is only the wrapper that transforms the array of
 * modules into a scalable SVG ready to print/scan.
 */
final class QrCode
{
    private const LEVELS = [
        'L' => 'QR_ERROR_CORRECT_LEVEL_L',
        'M' => 'QR_ERROR_CORRECT_LEVEL_M',
        'Q' => 'QR_ERROR_CORRECT_LEVEL_Q',
        'H' => 'QR_ERROR_CORRECT_LEVEL_H',
    ];

    /**
     * Beyond this length the QR becomes too dense to be useful.
     */
    private const MAX_BYTES = 1200;

    /**
     * SVG of the QR encoding $data (empty string if not possible).
     */
    public static function svg(
        string $data,
        int $moduleSize = 4,
        int $margin = 4,
        string $level = 'M',
        string $dark = 'currentColor'
    ): string {
        $data = trim($data);
        if ($data === '' || strlen($data) > self::MAX_BYTES) {
            return '';
        }
        $moduleSize = max(1, min(20, $moduleSize));
        $margin = max(0, min(16, $margin));
        $constName = self::LEVELS[strtoupper($level)] ?? 'QR_ERROR_CORRECT_LEVEL_M';

        $file = __DIR__ . '/QrCode/qrcode.php';
        if (!is_file($file)) {
            return '';
        }
        require_once $file;
        if (!class_exists('QRCode') || !defined($constName)) {
            return '';
        }

        try {
            /** @var object $qr */
            $qr = \QRCode::getMinimumQRCode($data, constant($constName));
            $count = (int) $qr->getModuleCount();
        } catch (\Throwable) {
            return '';
        }
        if ($count < 21 || $count > 177) {
            return '';
        }

        $total = $count + $margin * 2;
        $path = [];
        for ($row = 0; $row < $count; $row++) {
            for ($col = 0; $col < $count; $col++) {
                if (!$qr->isDark($row, $col)) {
                    continue;
                }
                $x = $col + $margin;
                $y = $row + $margin;
                $path[] = 'M' . $x . ' ' . $y . 'h1v1h-1z';
            }
        }
        $sizePx = $total * $moduleSize;

        return '<svg class="qr-code" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '"'
            . ' width="' . $sizePx . '" height="' . $sizePx . '" role="img" aria-label="QR code"'
            . ' shape-rendering="crispEdges">'
            . '<rect width="' . $total . '" height="' . $total . '" fill="#ffffff"/>'
            . '<path fill="' . e($dark === '' ? 'currentColor' : $dark) . '" d="' . implode('', $path) . '"/>'
            . '</svg>';
    }
}
