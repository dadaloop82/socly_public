<?php

declare(strict_types=1);

namespace Socly\Support;

/**
 * QR code in SVG lato server (nessuna estensione GD richiesta).
 *
 * L'encoder è di terze parti (Kazuhiko Arase, MIT) ed è vendored in
 * app/Support/QrCode/qrcode.php: qui c'è solo il wrapper che trasforma la
 * matrice di moduli in un SVG scalabile e pronto da stampare/scansionare.
 */
final class QrCode
{
    private const LEVELS = [
        'L' => 'QR_ERROR_CORRECT_LEVEL_L',
        'M' => 'QR_ERROR_CORRECT_LEVEL_M',
        'Q' => 'QR_ERROR_CORRECT_LEVEL_Q',
        'H' => 'QR_ERROR_CORRECT_LEVEL_H',
    ];

    /** Oltre questa lunghezza il QR diventa troppo denso per essere utile. */
    private const MAX_BYTES = 1200;

    /**
     * SVG del QR che codifica $data (stringa vuota se non è possibile).
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
