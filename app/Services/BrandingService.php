<?php

declare(strict_types=1);

namespace Socly\Services;

final class BrandingService
{
    private const MAX_LOGO_BYTES = 3 * 1024 * 1024;
    private const MIN_LOGO_PX = 100;
    /** @var list<string> */
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];

    public function __construct(
        private readonly SettingsService $settings
    ) {
    }

    public function logoRelativePath(): string
    {
        $path = trim((string) $this->settings->get('branding.logo', ''));
        return $path !== '' && !str_contains($path, '..') ? $path : '';
    }

    public function logoAbsolutePath(): ?string
    {
        $relative = $this->logoRelativePath();
        if ($relative === '') {
            return null;
        }
        return resolve_upload_absolute_path($relative);
    }

    public function logoUrl(): ?string
    {
        return $this->logoRelativePath() !== '' ? url('/branding/logo') : null;
    }

    /** @return list<array{id:string,name:string,primary:string,accent:string,source:string}> */
    public function paletteSuggestions(): array
    {
        $raw = $this->settings->get('branding.palette_suggestions', '');
        if (is_array($raw)) {
            return $this->normalizePalettes($raw);
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $this->normalizePalettes($decoded) : [];
    }

    /**
     * @param list<array<string,mixed>> $palettes
     * @return list<array{id:string,name:string,primary:string,accent:string,source:string}>
     */
    public function storePaletteSuggestions(array $palettes): array
    {
        $existing = $this->paletteSuggestions();
        $merged = [];
        // Newest suggestions first so the scraped logo palette leads the picker.
        foreach (array_merge($this->normalizePalettes($palettes), $existing) as $palette) {
            $key = strtolower($palette['primary'] . '|' . $palette['accent']);
            if (!isset($merged[$key])) {
                $merged[$key] = $palette;
            }
        }
        $list = array_values($merged);
        if (count($list) > 8) {
            $list = array_slice($list, 0, 8);
        }
        $this->settings->set('branding.palette_suggestions', json_encode($list, JSON_UNESCAPED_UNICODE));
        return $list;
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int}|null $file
     * @return array{ok:bool,path?:string,error?:string}
     */
    public function storeLogoUpload(?array $file, bool $replace = true): array
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true];
        }
        $error = $this->validateLogoFile($file);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }
        return $this->persistLogoFromPath((string) $file['tmp_name'], $replace);
    }

    /** @return array{ok:bool,path?:string,error?:string} */
    public function downloadLogoFromUrl(string $url, bool $replace = false): array
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'error' => 'invalid_url'];
        }
        if ($this->logoRelativePath() !== '' && !$replace) {
            return ['ok' => true, 'path' => $this->logoRelativePath()];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'socly-logo-');
        if ($tmp === false) {
            return ['ok' => false, 'error' => __('validation.photo')];
        }

        $body = $this->fetchBinary($url);
        if ($body === null || $body === '') {
            @unlink($tmp);
            return ['ok' => false, 'error' => __('validation.photo')];
        }
        file_put_contents($tmp, $body);
        $result = $this->persistLogoFromPath($tmp, true);
        @unlink($tmp);
        return $result;
    }

    /**
     * @param list<string> $hexColors
     * @return list<array{id:string,name:string,primary:string,accent:string,source:string}>
     */
    public function palettesFromColors(array $hexColors, string $source, string $namePrefix = ''): array
    {
        $colors = [];
        foreach ($hexColors as $hex) {
            $normalized = $this->normalizeHex((string) $hex);
            if ($normalized !== null && !$this->isNeutralColor($normalized)) {
                $colors[$normalized] = true;
            }
        }
        $list = array_keys($colors);
        if ($list === []) {
            return [];
        }

        $palettes = [];
        $primary = $list[0];
        $accent = $list[1] ?? $this->complementAccent($primary);
        [$primary, $accent] = $this->ensureDistinctColors($primary, $accent);
        $palettes[] = $this->paletteEntry($source . '_main', $namePrefix !== '' ? $namePrefix : __('setup.palette_from_site'), $primary, $accent, $source);

        if (isset($list[1], $list[2])) {
            [$altPrimary, $altAccent] = $this->ensureDistinctColors($list[1], $list[2]);
            // Prefer an alternative that does not clone the main pair.
            if (
                strtoupper($altPrimary . $altAccent) !== strtoupper($primary . $accent)
                && strtoupper($altPrimary . $altAccent) !== strtoupper($accent . $primary)
            ) {
                $palettes[] = $this->paletteEntry($source . '_alt', __('setup.palette_alt'), $altPrimary, $altAccent, $source);
            }
        }

        return $palettes;
    }

    /** @return list<array{id:string,name:string,primary:string,accent:string,source:string}> */
    public function palettesFromLogoFile(?string $absolutePath = null): array
    {
        $path = $absolutePath ?? $this->logoAbsolutePath();
        if ($path === null || !is_file($path)) {
            return [];
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        if ($mime === 'image/svg+xml') {
            return [];
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            return [];
        }
        $colors = $this->dominantColorsFromImageData($data, 2);
        if ($colors === []) {
            return [];
        }
        $primary = $colors[0];
        $accent = $colors[1] ?? $this->complementAccent($primary);
        [$primary, $accent] = $this->ensureDistinctColors($primary, $accent);
        return [$this->paletteEntry('logo_main', __('setup.palette_from_logo'), $primary, $accent, 'logo')];
    }

    /**
     * @return list<string>
     */
    public function dominantColorsFromImageData(string $data, int $limit = 2): array
    {
        if (!function_exists('imagecreatefromstring')) {
            return [];
        }
        $img = @imagecreatefromstring($data);
        if ($img === false) {
            return [];
        }
        $width = imagesx($img);
        $height = imagesy($img);
        if ($width < 1 || $height < 1) {
            imagedestroy($img);
            return [];
        }

        $sample = imagecreatetruecolor(64, 64);
        imagealphablending($sample, false);
        imagesavealpha($sample, true);
        imagecopyresampled($sample, $img, 0, 0, 0, 0, 64, 64, $width, $height);
        imagedestroy($img);

        /** @var array<string,float> $scores */
        $scores = [];
        /** @var array<string,float> $edgeScores */
        $edgeScores = [];
        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $rgba = imagecolorat($sample, $x, $y);
                $a = ($rgba & 0x7F000000) >> 24;
                if ($a > 40) {
                    continue;
                }
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                $max = max($r, $g, $b);
                $min = min($r, $g, $b);
                $delta = $max - $min;
                $lightness = ($max + $min) / 510;
                if ($lightness > 0.90 || $lightness < 0.08 || $delta < 28) {
                    continue;
                }
                $saturation = $max > 0 ? $delta / $max : 0;
                if ($saturation < 0.28) {
                    continue;
                }
                $dx = abs($x - 31.5) / 31.5;
                $dy = abs($y - 31.5) / 31.5;
                $radius = min(1.0, sqrt(($dx * $dx) + ($dy * $dy)));
                $center = 1.0 - $radius;
                $centerWeight = 0.35 + ($center * 2.4);
                $isEdge = $x < 4 || $y < 4 || $x > 59 || $y > 59 || $radius > 0.82;

                $br = (int) (round($r / 20) * 20);
                $bg = (int) (round($g / 20) * 20);
                $bb = (int) (round($b / 20) * 20);
                $hex = sprintf('#%02X%02X%02X', min(255, $br), min(255, $bg), min(255, $bb));
                $w = (1.0 + ($saturation * 5.0) + ((0.5 - abs($lightness - 0.45)) * 1.5)) * $centerWeight;
                $scores[$hex] = ($scores[$hex] ?? 0) + $w;
                if ($isEdge) {
                    $edgeScores[$hex] = ($edgeScores[$hex] ?? 0) + $w;
                }
            }
        }
        imagedestroy($sample);

        if ($scores === []) {
            return [];
        }
        $filtered = $scores;
        // Drop background chrome: colors that live mostly on the border.
        foreach ($filtered as $hex => $score) {
            $edge = $edgeScores[$hex] ?? 0.0;
            if ($score > 0 && ($edge / $score) > 0.72) {
                unset($filtered[$hex]);
            }
        }
        // Full-bleed logos often live near the sample edge — keep original scores then.
        if ($filtered === []) {
            $filtered = $scores;
        }
        arsort($filtered);
        $picked = [];
        foreach (array_keys($filtered) as $hex) {
            if ($this->colorTooCloseToAny($hex, $picked)) {
                continue;
            }
            $picked[] = $hex;
            if (count($picked) >= $limit) {
                break;
            }
        }
        return $picked;
    }

    /** @param list<string> $existing */
    private function colorTooCloseToAny(string $hex, array $existing): bool
    {
        [$r, $g, $b] = $this->rgbFromHex($hex);
        foreach ($existing as $other) {
            [$or, $og, $ob] = $this->rgbFromHex($other);
            $dr = $r - $or;
            $dg = $g - $og;
            $db = $b - $ob;
            if (($dr * $dr + $dg * $dg + $db * $db) < (55 * 55)) {
                return true;
            }
        }
        return false;
    }

    public function refreshLogoPalettes(): void
    {
        $fromLogo = $this->palettesFromLogoFile();
        if ($fromLogo !== []) {
            $this->storePaletteSuggestions($fromLogo);
        }
    }

    /** @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file */
    private function validateLogoFile(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return __('validation.photo');
        }
        if (($file['size'] ?? 0) > self::MAX_LOGO_BYTES) {
            return __('validation.photo');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return __('validation.photo');
        }
        return null;
    }

    /** @return array{ok:bool,path?:string,error?:string} */
    private function persistLogoFromPath(string $sourcePath, bool $replace): array
    {
        if (!is_file($sourcePath)) {
            return ['ok' => false, 'error' => __('validation.photo')];
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($sourcePath) ?: '';
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            default => null,
        };
        if ($ext === null) {
            return ['ok' => false, 'error' => __('validation.photo')];
        }

        if ($ext !== 'svg') {
            $raw = @file_get_contents($sourcePath);
            if ($raw === false) {
                return ['ok' => false, 'error' => __('validation.photo')];
            }
            $info = @getimagesizefromstring($raw);
            $width = is_array($info) ? (int) ($info[0] ?? 0) : 0;
            $height = is_array($info) ? (int) ($info[1] ?? 0) : 0;
            if ($width < self::MIN_LOGO_PX || $height < self::MIN_LOGO_PX) {
                return ['ok' => false, 'error' => __('validation.photo')];
            }
        }

        $paths = user_upload_paths('branding', null, 'logo.' . $ext);
        $relative = $paths['relative'];
        $absolute = $paths['absolute'];
        if ($replace && $this->logoRelativePath() !== '' && $this->logoRelativePath() !== $relative) {
            $old = resolve_upload_absolute_path($this->logoRelativePath());
            if ($old !== null) {
                @unlink($old);
            }
        }

        if (!@copy($sourcePath, $absolute)) {
            return ['ok' => false, 'error' => __('validation.photo')];
        }
        @chmod($absolute, 0644);
        $this->settings->set('branding.logo', $relative);
        $this->refreshLogoPalettes();
        $confirmed = $this->settings->get('branding.colors_confirmed', null);
        if ($confirmed === null || $confirmed === '') {
            $fromLogo = $this->palettesFromLogoFile($absolute);
            if ($fromLogo !== []) {
                $primary = (string) ($fromLogo[0]['primary'] ?? '');
                $accent = (string) ($fromLogo[0]['accent'] ?? '');
                if ($primary !== '') {
                    [$primary, $accent] = $this->ensureDistinctColors($primary, $accent !== '' ? $accent : $primary);
                    $this->settings->set('branding.primary', $primary);
                    $this->settings->set('branding.accent', $accent);
                }
            }
        }
        return ['ok' => true, 'path' => $relative];
    }

    private function fetchBinary(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 12,
                    'header' => "User-Agent: SoclySetupScrape/1.0\r\nAccept: image/*,*/*;q=0.8\r\n",
                    'follow_location' => 1,
                    'max_redirects' => 3,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            return is_string($body) && strlen($body) <= self::MAX_LOGO_BYTES ? $body : null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_USERAGENT => 'SoclySetupScrape/1.0',
            CURLOPT_HTTPHEADER => ['Accept: image/*,*/*;q=0.8'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $code >= 400 || strlen($body) > self::MAX_LOGO_BYTES) {
            return null;
        }
        return $body;
    }

    /** @param list<array<string,mixed>> $items */
    private function normalizePalettes(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $primary = $this->normalizeHex((string) ($item['primary'] ?? ''));
            $accent = $this->normalizeHex((string) ($item['accent'] ?? ''));
            if ($primary === null || $accent === null) {
                continue;
            }
            [$primary, $accent] = $this->ensureDistinctColors($primary, $accent);
            $out[] = [
                'id' => (string) ($item['id'] ?? md5($primary . $accent)),
                'name' => (string) ($item['name'] ?? __('setup.palette_custom')),
                'primary' => $primary,
                'accent' => $accent,
                'source' => (string) ($item['source'] ?? 'custom'),
            ];
        }
        return $out;
    }

    /** @return array{id:string,name:string,primary:string,accent:string,source:string} */
    private function paletteEntry(string $id, string $name, string $primary, string $accent, string $source): array
    {
        $primary = $this->normalizeHex($primary) ?? '#0D6E66';
        $accent = $this->normalizeHex($accent) ?? '#B84A1B';
        [$primary, $accent] = $this->ensureDistinctColors($primary, $accent);
        return [
            'id' => $id,
            'name' => $name,
            'primary' => $primary,
            'accent' => $accent,
            'source' => $source,
        ];
    }

    /**
     * Guarantee primary and accent are visually distinct (never identical / near-identical).
     *
     * @return array{0:string,1:string}
     */
    public function ensureDistinctColors(string $primary, string $accent): array
    {
        $primary = $this->normalizeHex($primary) ?? '#0D6E66';
        $accent = $this->normalizeHex($accent) ?? '#B84A1B';
        if (!$this->colorsTooClose($primary, $accent)) {
            return [$primary, $accent];
        }

        $candidates = [
            $this->complementAccent($primary),
            $this->shiftHue($primary, 0.28),
            $this->shiftHue($primary, 0.55),
            '#B84A1B',
            '#0D6E66',
            '#C45C26',
            '#1F6F8B',
        ];
        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeHex($candidate);
            if ($normalized === null) {
                continue;
            }
            if (!$this->colorsTooClose($primary, $normalized)) {
                return [$primary, $normalized];
            }
        }

        // Last resort: hard-nudge RGB so the pair cannot collapse.
        [$r, $g, $b] = $this->rgbFromHex($primary);
        $fallback = sprintf(
            '#%02X%02X%02X',
            ($r + 140) % 256,
            ($g + 90) % 256,
            ($b + 40) % 256
        );
        if ($this->colorsTooClose($primary, $fallback)) {
            $fallback = $primary === '#0D6E66' ? '#B84A1B' : '#0D6E66';
        }
        return [$primary, $fallback];
    }

    private function colorsTooClose(string $a, string $b): bool
    {
        if (strtoupper($a) === strtoupper($b)) {
            return true;
        }
        return $this->colorTooCloseToAny($a, [$b]);
    }

    private function shiftHue(string $hex, float $delta): string
    {
        [$r, $g, $b] = $this->rgbFromHex($hex);
        $hsl = $this->rgbToHsl($r, $g, $b);
        $h = fmod($hsl[0] + $delta + 1.0, 1.0);
        $s = max(0.35, min(0.78, $hsl[1] < 0.2 ? 0.55 : $hsl[1] + 0.1));
        $l = max(0.28, min(0.58, $hsl[2]));
        [$nr, $ng, $nb] = $this->hslToRgb($h, $s, $l);
        return sprintf('#%02X%02X%02X', $nr, $ng, $nb);
    }

    private function normalizeHex(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^#([0-9A-Fa-f]{6})$/', $value, $m)) {
            return '#' . strtoupper($m[1]);
        }
        if (preg_match('/^#([0-9A-Fa-f]{3})$/', $value, $m)) {
            $h = strtoupper($m[1]);
            return '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        return null;
    }

    private function bucketHex(string $hex): string
    {
        [$r, $g, $b] = $this->rgbFromHex($hex);
        $r = (int) (round($r / 24) * 24);
        $g = (int) (round($g / 24) * 24);
        $b = (int) (round($b / 24) * 24);
        return sprintf('#%02X%02X%02X', min(255, $r), min(255, $g), min(255, $b));
    }

    private function isNeutralColor(string $hex): bool
    {
        [$r, $g, $b] = $this->rgbFromHex($hex);
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;
        $lightness = ($max + $min) / 510;
        if ($lightness > 0.93 || $lightness < 0.08) {
            return true;
        }
        return $delta < 18;
    }

    /** @return array{0:int,1:int,2:int} */
    private function rgbFromHex(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private function complementAccent(string $primary): string
    {
        [$r, $g, $b] = $this->rgbFromHex($primary);
        $hsl = $this->rgbToHsl($r, $g, $b);
        $h = fmod($hsl[0] + 0.42, 1.0);
        [$nr, $ng, $nb] = $this->hslToRgb($h, min(0.72, $hsl[1] + 0.08), min(0.52, max(0.28, $hsl[2])));
        return sprintf('#%02X%02X%02X', $nr, $ng, $nb);
    }

    /** @return array{0:float,1:float,2:float} */
    private function rgbToHsl(int $r, int $g, int $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $h = $s = $l = ($max + $min) / 2;
        if ($max === $min) {
            $h = $s = 0;
        } else {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
            $h = match ($max) {
                $r => fmod((($g - $b) / $d) + ($g < $b ? 6 : 0), 6) / 6,
                $g => (($b - $r) / $d + 2) / 6,
                default => (($r - $g) / $d + 4) / 6,
            };
        }
        return [$h, $s, $l];
    }

    /** @return array{0:int,1:int,2:int} */
    private function hslToRgb(float $h, float $s, float $l): array
    {
        if ($s <= 0) {
            $v = (int) round($l * 255);
            return [$v, $v, $v];
        }
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $r = $this->hueToRgb($p, $q, $h + 1 / 3);
        $g = $this->hueToRgb($p, $q, $h);
        $b = $this->hueToRgb($p, $q, $h - 1 / 3);
        return [(int) round($r * 255), (int) round($g * 255), (int) round($b * 255)];
    }

    private function hueToRgb(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }
        if ($t > 1) {
            $t -= 1;
        }
        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }
        if ($t < 1 / 2) {
            return $q;
        }
        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }
        return $p;
    }
}
