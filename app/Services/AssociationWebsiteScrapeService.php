<?php

declare(strict_types=1);

namespace Socly\Services;

/**
 * Best-effort scrape of an association website (max ~60s wall time).
 */
final class AssociationWebsiteScrapeService
{
    private const BUDGET_SECONDS = 55;
    private const PER_REQUEST_TIMEOUT = 12;
    private const MIN_LOGO_PX = 100;
    private const MAX_LOGO_PROBES = 6;

    /** @var list<string> */
    private const EXTRA_PATHS = [
        '/contatti',
        '/contatti/',
        '/contact',
        '/contacts',
        '/chi-siamo',
        '/chi-siamo/',
        '/about',
        '/about-us',
        '/dove-siamo',
        '/sede',
        '/privacy',
        '/trasparenza',
        '/organi',
        '/organi/',
        '/organi-sociali',
        '/direttivo',
        '/consiglio',
        '/consiglio-direttivo',
        '/governance',
        '/struttura',
        '/chi_siamo',
    ];

    /**
     * @param null|callable(array<string, mixed>): void $onEvent
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   source_url?: string,
     *   pages_fetched?: int,
     *   elapsed_ms?: int,
     *   found?: array<string, string>,
     *   labels?: array<string, string>,
     *   theme_colors?: list<string>,
     *   canonical_url?: string
     * }
     */
    public function scrape(string $website, string $associationName = '', ?callable $onEvent = null): array
    {
        $emit = static function (array $event) use ($onEvent): void {
            if ($onEvent !== null) {
                $onEvent($event);
            }
        };

        $started = microtime(true);
        $url = $this->normalizeUrl($website);
        if ($url === null) {
            $emit(['type' => 'error', 'error' => 'invalid_url']);
            return ['ok' => false, 'error' => 'invalid_url'];
        }
        if (!$this->isSafePublicUrl($url)) {
            $emit(['type' => 'error', 'error' => 'unsafe_url']);
            return ['ok' => false, 'error' => 'unsafe_url'];
        }

        $emit(['type' => 'start', 'url' => $url]);

        $deadline = $started + self::BUDGET_SECONDS;
        $pages = 0;
        $blob = '';
        $urls = $this->candidateUrls($url);
        $canonicalUrl = $url;
        /** @var array<string, string> $announced */
        $announced = [];

        foreach ($urls as $candidate) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $remaining = max(1, (int) floor($deadline - microtime(true)));
            $emit([
                'type' => 'progress',
                'phase' => 'fetch',
                'pages' => $pages,
                'url' => $candidate,
            ]);
            $effectiveUrl = null;
            $html = $this->fetchHtml($candidate, min(self::PER_REQUEST_TIMEOUT, $remaining), $effectiveUrl);
            if ($html === null || $html === '') {
                continue;
            }
            if ($pages === 0 && $effectiveUrl !== null && $effectiveUrl !== '') {
                $canonicalUrl = $this->normalizeUrl($effectiveUrl) ?? $canonicalUrl;
            }
            $pages++;
            $blob .= "\n" . $html;

            $emit([
                'type' => 'progress',
                'phase' => 'pages',
                'pages' => $pages,
                'url' => $canonicalUrl,
            ]);

            $partial = $this->extract($blob, $associationName, $canonicalUrl);
            foreach ($partial as $key => $value) {
                $value = trim((string) $value);
                if ($value === '' || isset($announced[$key])) {
                    continue;
                }
                $announced[$key] = $value;
                $emit([
                    'type' => 'found',
                    'key' => $key,
                    'label' => $this->labelForKey($key),
                    'value' => $value,
                ]);
            }

            // Prefer contact pages once we have a solid amount of text
            if ($pages >= 4 && mb_strlen(strip_tags($blob)) > 8000) {
                break;
            }
        }

        if ($pages === 0) {
            $payload = [
                'ok' => false,
                'error' => 'fetch_failed',
                'source_url' => $url,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
            $emit([
                'type' => 'error',
                'error' => 'fetch_failed',
                'elapsed_ms' => $payload['elapsed_ms'],
            ]);
            return $payload;
        }

        $emit(['type' => 'progress', 'phase' => 'extract', 'pages' => $pages]);

        $found = $this->extract($blob, $associationName, $canonicalUrl);
        foreach ($found as $key => $value) {
            $value = trim((string) $value);
            if ($value === '' || isset($announced[$key])) {
                continue;
            }
            $announced[$key] = $value;
            $emit([
                'type' => 'found',
                'key' => $key,
                'label' => $this->labelForKey($key),
                'value' => $value,
            ]);
        }

        $labels = [];
        foreach (array_keys($found) as $key) {
            $labels[$key] = $this->labelForKey((string) $key);
        }

        return [
            'ok' => true,
            'source_url' => $url,
            'canonical_url' => $canonicalUrl,
            'pages_fetched' => $pages,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'found' => $found,
            'labels' => $labels,
            'theme_colors' => $this->themeColorsFromFound($found),
        ];
    }

    public function labelForKey(string $key): string
    {
        return match ($key) {
            'email' => __('setup.field_email'),
            'pec' => __('setup.field_pec'),
            'phone' => __('setup.field_phone'),
            'fiscal_code' => __('setup.field_fiscal_code'),
            'vat_number' => __('setup.field_vat_number'),
            'city' => __('setup.field_city'),
            'postal_code' => __('setup.field_postal_code'),
            'address' => __('setup.field_street'),
            'house_number' => __('setup.field_house_number'),
            'runts' => __('setup.step_runts_title'),
            'president_name' => __('setup.scrape_president'),
            'vice_president_name' => __('setup.scrape_vice_president'),
            'secretary_name' => __('setup.scrape_secretary'),
            'treasurer_name' => __('setup.scrape_treasurer'),
            'board_names' => __('setup.scrape_board'),
            'logo_url' => __('setup.field_logo'),
            'theme_primary' => __('setup.field_primary'),
            'theme_accent' => __('setup.field_accent'),
            'website' => __('setup.field_website'),
            default => $key,
        };
    }

    public function normalizeUrl(string $raw): ?string
    {
        $raw = trim($raw);
        $raw = preg_replace('/\s+/', '', $raw) ?? $raw;
        $raw = rtrim($raw, '.,;');
        if ($raw === '') {
            return null;
        }
        $raw = preg_replace('#^(?:URL|Sito|Website)\s*[:=]\s*#iu', '', $raw) ?? $raw;
        if (!preg_match('#^https?://#i', $raw)) {
            $raw = preg_replace('#^//#', '', $raw) ?? $raw;
            $raw = 'https://' . $raw;
        }
        $parts = parse_url($raw);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host) && !filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
        return $scheme . '://' . $host . ($path === '' ? '/' : $path) . $query;
    }

    private function isSafePublicUrl(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        $ips = gethostbynamel($host) ?: [];
        if ($ips === []) {
            // Allow DNS failure later at fetch time; host looked like a domain.
            return (bool) preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host);
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    private function candidateUrls(string $home): array
    {
        $parts = parse_url($home);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        $out = [$home];
        if (rtrim($home, '/') !== $origin) {
            $out[] = $origin . '/';
        }
        foreach (self::EXTRA_PATHS as $path) {
            $out[] = $origin . $path;
        }
        return array_values(array_unique($out));
    }

    private function fetchHtml(string $url, int $timeout, ?string &$effectiveUrl = null): ?string
    {
        $effectiveUrl = $url;
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => $timeout,
                    'header' => "User-Agent: SoclySetupScrape/1.0\r\nAccept: text/html\r\n",
                    'follow_location' => 1,
                    'max_redirects' => 3,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach (array_reverse($http_response_header) as $line) {
                    if (preg_match('/^Location:\s*(.+)$/i', trim($line), $m)) {
                        $loc = trim($m[1]);
                        if ($loc !== '') {
                            $effectiveUrl = str_starts_with($loc, 'http') ? $loc : $url;
                        }
                        break;
                    }
                }
            }
            return is_string($body) ? $body : null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'SoclySetupScrape/1.0 (association setup helper)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $resolved = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        if ($resolved !== '') {
            $effectiveUrl = $resolved;
        }
        if (!is_string($body) || $code >= 400) {
            return null;
        }
        // Cap page size
        if (strlen($body) > 1_500_000) {
            $body = substr($body, 0, 1_500_000);
        }
        return $body;
    }

    /**
     * @return array<string, string>
     */
    private function extract(string $html, string $associationName, string $baseUrl): array
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $found = [];

        $emails = $this->extractEmails($html . ' ' . $text);
        foreach ($emails as $email) {
            if ($this->looksLikePec($email) && empty($found['pec'])) {
                $found['pec'] = $email;
            } elseif (empty($found['email']) && !$this->looksLikePec($email)) {
                $found['email'] = $email;
            }
        }
        if (empty($found['email']) && !empty($emails[0])) {
            $found['email'] = $emails[0];
        }

        $phone = $this->extractPhone($html . "\n" . $text);
        if ($phone !== null) {
            $found['phone'] = $phone;
        }

        $vat = $this->extractVat($text);
        if ($vat !== null) {
            $found['vat_number'] = $vat;
        }

        $cf = $this->extractFiscalCode($text, $vat);
        if ($cf !== null) {
            $found['fiscal_code'] = $cf;
        }

        $runts = $this->extractRunts($text . "\n" . $html);
        if ($runts !== null) {
            $found['runts'] = $runts;
        }

        $addr = $this->extractAddress($text, $html);
        foreach ($addr as $k => $v) {
            if ($v !== '') {
                $found[$k] = $v;
            }
        }

        foreach ($this->extractOfficers($text, $html) as $k => $v) {
            if ($v !== '') {
                $found[$k] = $v;
            }
        }

        $logo = $this->extractUsableLogo($html, $baseUrl);
        if ($logo !== null) {
            $found['logo_url'] = $logo['url'];
            // Colors must come from the logo itself — never from random CSS hex in the page.
            if (!empty($logo['primary'])) {
                $found['theme_primary'] = $logo['primary'];
            }
            if (!empty($logo['accent'])) {
                $found['theme_accent'] = $logo['accent'];
            }
        }

        unset($associationName);

        return $found;
    }

    /** @return array<string,string> */
    private function extractThemeColors(string $html): array
    {
        // Kept for compatibility; scrape no longer uses page CSS hex dumps for branding.
        unset($html);
        return [];
    }

    /** @param array<string,string> $found */
    private function themeColorsFromFound(array $found): array
    {
        $out = [];
        if (!empty($found['theme_primary'])) {
            $out[] = $found['theme_primary'];
        }
        if (!empty($found['theme_accent'])) {
            $out[] = $found['theme_accent'];
        }
        return $out;
    }

    private function extractUsableLogoUrl(string $html, string $baseUrl): ?string
    {
        $logo = $this->extractUsableLogo($html, $baseUrl);
        return $logo['url'] ?? null;
    }

    /**
     * @return array{url:string,primary?:string,accent?:string}|null
     */
    private function extractUsableLogo(string $html, string $baseUrl): ?array
    {
        $candidates = $this->collectLogoCandidates($html, $baseUrl);
        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $candidates = array_slice($candidates, 0, self::MAX_LOGO_PROBES);

        $bestSvg = null;
        foreach ($candidates as $candidate) {
            $url = $candidate['url'];
            if ($this->looksLikeFaviconUrl($url) && $candidate['score'] < 40) {
                continue;
            }

            $probed = $this->probeLogoAsset($url);
            if ($probed === null) {
                if ($bestSvg === null && ($candidate['svg'] || $candidate['score'] >= 55)) {
                    $bestSvg = ['url' => $url];
                }
                continue;
            }
            if ($probed['width'] >= self::MIN_LOGO_PX && $probed['height'] >= self::MIN_LOGO_PX) {
                $out = ['url' => $url];
                if (!empty($probed['primary'])) {
                    $out['primary'] = $probed['primary'];
                }
                if (!empty($probed['accent'])) {
                    $out['accent'] = $probed['accent'];
                }
                return $out;
            }
        }

        return $bestSvg;
    }

    /**
     * @return array{width:int,height:int,primary?:string,accent?:string}|null
     */
    private function probeLogoAsset(string $url): ?array
    {
        if (preg_match('/\.svg(?:$|\?)/i', $url)) {
            return null;
        }

        $body = $this->fetchBinaryLimited($url, 1_500_000, 6);
        if ($body === null || $body === '') {
            return null;
        }

        $info = @getimagesizefromstring($body);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return null;
        }

        $out = [
            'width' => (int) $info[0],
            'height' => (int) $info[1],
        ];

        $colors = $this->dominantColorsFromImageData($body, 2);
        if (isset($colors[0])) {
            $out['primary'] = $colors[0];
        }
        if (isset($colors[1])) {
            $out['accent'] = $colors[1];
        }
        return $out;
    }

    /**
     * Saturation-weighted dominant colors from raster image bytes.
     *
     * @return list<string> hex colors
     */
    private function dominantColorsFromImageData(string $data, int $limit = 2): array
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
        foreach ($filtered as $hex => $score) {
            $edge = $edgeScores[$hex] ?? 0.0;
            if ($score > 0 && ($edge / $score) > 0.72) {
                unset($filtered[$hex]);
            }
        }
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
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        foreach ($existing as $other) {
            $other = ltrim($other, '#');
            $dr = $r - hexdec(substr($other, 0, 2));
            $dg = $g - hexdec(substr($other, 2, 2));
            $db = $b - hexdec(substr($other, 4, 2));
            if (($dr * $dr + $dg * $dg + $db * $db) < (55 * 55)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<array{url:string,score:int,svg:bool}>
     */
    private function collectLogoCandidates(string $html, string $baseUrl): array
    {
        /** @var array<string, array{url:string,score:int,svg:bool}> $byUrl */
        $byUrl = [];

        $add = function (string $rawUrl, int $score, bool $forceSvg = false) use (&$byUrl, $baseUrl): void {
            $resolved = $this->resolveAssetUrl($rawUrl, $baseUrl);
            if ($resolved === null) {
                return;
            }
            $svg = $forceSvg || (bool) preg_match('/\.svg(?:$|\?)/i', $resolved);
            if (isset($byUrl[$resolved])) {
                $byUrl[$resolved]['score'] = max($byUrl[$resolved]['score'], $score);
                $byUrl[$resolved]['svg'] = $byUrl[$resolved]['svg'] || $svg;
                return;
            }
            $byUrl[$resolved] = ['url' => $resolved, 'score' => $score, 'svg' => $svg];
        };

        if (preg_match_all('/<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $url) {
                $add(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 55);
            }
        }
        if (preg_match_all('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image(?::secure_url)?["\']/i', $html, $m)) {
            foreach ($m[1] as $url) {
                $add(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 55);
            }
        }
        if (preg_match_all('/<meta[^>]+name=["\']twitter:image(?::src)?["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $url) {
                $add(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 50);
            }
        }

        if (preg_match_all('/<link\b([^>]+)>/i', $html, $links)) {
            foreach ($links[1] as $attrs) {
                if (!preg_match('/\brel=["\']([^"\']+)["\']/i', $attrs, $relM)) {
                    continue;
                }
                $rel = strtolower($relM[1]);
                if (!preg_match('/icon|apple-touch-icon|mask-icon|image_src/i', $rel)) {
                    continue;
                }
                if (!preg_match('/\bhref=["\']([^"\']+)["\']/i', $attrs, $hrefM)) {
                    continue;
                }
                $score = 5;
                if (str_contains($rel, 'apple-touch-icon')) {
                    $score = 28;
                } elseif (str_contains($rel, 'image_src')) {
                    $score = 45;
                }
                if (preg_match('/\bsizes=["\']([^"\']+)["\']/i', $attrs, $sizesM)) {
                    if (preg_match_all('/(\d+)\s*x\s*(\d+)/i', $sizesM[1], $sm, PREG_SET_ORDER)) {
                        $maxSide = 0;
                        foreach ($sm as $pair) {
                            $maxSide = max($maxSide, (int) $pair[1], (int) $pair[2]);
                        }
                        if ($maxSide > 0 && $maxSide < self::MIN_LOGO_PX) {
                            continue;
                        }
                        if ($maxSide >= 180) {
                            $score += 20;
                        } elseif ($maxSide >= self::MIN_LOGO_PX) {
                            $score += 12;
                        }
                    }
                }
                $add(html_entity_decode($hrefM[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $score);
            }
        }

        if (preg_match_all('/<img\b([^>]+)>/i', $html, $imgs)) {
            foreach ($imgs[1] as $attrs) {
                $src = '';
                if (preg_match('/\bsrc=["\']([^"\']+)["\']/i', $attrs, $srcM)) {
                    $src = html_entity_decode($srcM[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                if (preg_match('/\bsrcset=["\']([^"\']+)["\']/i', $attrs, $srcsetM)) {
                    $bestFromSet = $this->largestFromSrcset($srcsetM[1]);
                    if ($bestFromSet !== null) {
                        $src = $bestFromSet;
                    }
                }
                if ($src === '') {
                    continue;
                }

                $classIdAlt = '';
                if (preg_match('/\b(?:class|id|alt|title|aria-label)=["\']([^"\']*)["\']/i', $attrs, $lab)) {
                    $classIdAlt = strtolower($lab[1]);
                }
                // Collect all label-ish attributes.
                if (preg_match_all('/\b(?:class|id|alt|title|aria-label)=["\']([^"\']*)["\']/i', $attrs, $labs)) {
                    $classIdAlt = strtolower(implode(' ', $labs[1]));
                }

                $score = 0;
                if (preg_match('/\blogo\b|\bbrand\b|\bsite[-_]?title\b|\bheader[-_]?logo\b/i', $classIdAlt)) {
                    $score += 60;
                }
                if (preg_match('/\blogo\b|\bbrand\b/i', $src)) {
                    $score += 25;
                }
                if (preg_match('/favicon|icon[-_]?\d{2,3}|sprite|badge|avatar|emoji/i', $src . ' ' . $classIdAlt)) {
                    $score -= 70;
                }

                $w = 0;
                $h = 0;
                if (preg_match('/\bwidth=["\']?(\d+)/i', $attrs, $wm)) {
                    $w = (int) $wm[1];
                }
                if (preg_match('/\bheight=["\']?(\d+)/i', $attrs, $hm)) {
                    $h = (int) $hm[1];
                }
                if ($w > 0 && $h > 0) {
                    if ($w >= self::MIN_LOGO_PX && $h >= self::MIN_LOGO_PX) {
                        $score += 35;
                    } elseif ($w < self::MIN_LOGO_PX || $h < self::MIN_LOGO_PX) {
                        $score -= 40;
                    }
                }

                // Likely decorative / social icons.
                if (preg_match('/\b(facebook|instagram|twitter|linkedin|youtube|whatsapp)\b/i', $classIdAlt . $src)) {
                    $score -= 80;
                }

                if ($score < 15) {
                    continue;
                }
                $add($src, $score);
            }
        }

        // CSS background-image urls that mention logo.
        if (preg_match_all('/background(?:-image)?\s*:\s*url\((["\']?)([^)\'"]+)\1\)/i', $html, $bgs, PREG_SET_ORDER)) {
            foreach ($bgs as $bg) {
                $url = html_entity_decode($bg[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (preg_match('/logo|brand/i', $url)) {
                    $add($url, 40);
                }
            }
        }

        return array_values($byUrl);
    }

    private function largestFromSrcset(string $srcset): ?string
    {
        $best = null;
        $bestW = -1;
        foreach (preg_split('/\s*,\s*/', trim($srcset)) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (!preg_match('/^(\S+)(?:\s+(\d+)w)?/i', $part, $m)) {
                continue;
            }
            $w = isset($m[2]) ? (int) $m[2] : 0;
            if ($w >= $bestW) {
                $bestW = $w;
                $best = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return $best;
    }

    private function looksLikeFaviconUrl(string $url): bool
    {
        return (bool) preg_match('/favicon|apple-touch-icon|android-chrome|mstile|icon[-_]?(16|32|48|64)(?:\D|$)/i', $url);
    }

    private function fetchBinaryLimited(string $url, int $maxBytes, int $timeout): ?string
    {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => $timeout,
                    'header' => "User-Agent: SoclySetupScrape/1.0\r\nAccept: image/*,*/*;q=0.8\r\n",
                    'follow_location' => 1,
                    'max_redirects' => 3,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            if (!is_string($body) || $body === '') {
                return null;
            }
            return strlen($body) > $maxBytes ? substr($body, 0, $maxBytes) : $body;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'SoclySetupScrape/1.0',
            CURLOPT_HTTPHEADER => ['Accept: image/*,*/*;q=0.8'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $code >= 400) {
            return null;
        }
        if (strlen($body) > $maxBytes) {
            $body = substr($body, 0, $maxBytes);
        }
        return $body;
    }

    private function resolveAssetUrl(string $url, string $baseUrl): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, 'data:')) {
            return null;
        }
        if (preg_match('#^https?://#i', $url)) {
            return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
        }
        $parts = parse_url($baseUrl);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (str_starts_with($url, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $url;
        }
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }
        $path = $parts['path'] ?? '/';
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
        return $origin . ($dir === '' || $dir === '.' ? '' : $dir) . '/' . ltrim($url, '/');
    }

    /** @return list<string> */
    private function extractEmails(string $raw): array
    {
        $emails = [];
        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $raw, $m)) {
            foreach ($m[0] as $email) {
                $email = strtolower($email);
                if (str_contains($email, 'example.') || str_contains($email, 'sentry.') || str_contains($email, 'wixpress')) {
                    continue;
                }
                $emails[] = $email;
            }
        }
        return array_values(array_unique($emails));
    }

    private function looksLikePec(string $email): bool
    {
        return (bool) preg_match('/@(?:.*\.)?(pec\.it|legalmail\.it|gigapec\.it|arubapec\.it|pec\.|postecert\.it)/i', $email)
            || str_contains($email, 'pec.');
    }

    private function extractPhone(string $raw): ?string
    {
        if (preg_match_all('/(?:tel:|telefono|phone|cell\.?|mobile)[^\d+]{0,12}(\+?39[\s.\-]*)?(0\d{5,10}|3\d{8,9})/iu', $raw, $m)) {
            return $this->formatPhone(($m[2][0] ?? ''));
        }
        if (preg_match_all('/(?<!\d)(\+39[\s.\-]*)?(0\d{1,3}[\s.\-]?\d{5,8}|3\d{2}[\s.\-]?\d{6,7})(?!\d)/', $raw, $m)) {
            return $this->formatPhone(preg_replace('/\D+/', '', $m[0][0] ?? '') ?? '');
        }
        return null;
    }

    private function formatPhone(string $digits): ?string
    {
        $digits = preg_replace('/\D+/', '', $digits) ?? '';
        if (str_starts_with($digits, '39') && strlen($digits) > 10) {
            $digits = substr($digits, 2);
        }
        if (preg_match('/^(3\d{8,9}|0\d{5,10})$/', $digits)) {
            return $digits;
        }
        return null;
    }

    private function extractVat(string $text): ?string
    {
        if (preg_match('/(?:partita\s*iva|p\.?\s*iva|vat)\s*[:\-]?\s*(?:IT)?[\s]*(\d{11})/iu', $text, $m)) {
            return $m[1];
        }
        return null;
    }

    private function extractFiscalCode(string $text, ?string $vat): ?string
    {
        if (preg_match('/(?:codice\s*fiscale|c\.?\s*f\.?)\s*[:\-]?\s*([A-Z0-9]{11,16})/iu', $text, $m)) {
            return strtoupper($m[1]);
        }
        // Many associations use 11-digit CF equal to VAT
        if ($vat !== null) {
            return $vat;
        }
        if (preg_match('/(?<![A-Z0-9])(\d{11})(?![A-Z0-9])/', $text, $m)) {
            return $m[1];
        }
        return null;
    }

    private function extractRunts(string $text): ?string
    {
        $patterns = [
            '/(?:iscri(?:tt[oa]|zione)\s+(?:al\s+)?(?:RUNTS|Registro\s+Unico\s+Nazionale(?:\s+del\s+Terzo\s+Settore)?))\s*(?:con\s+(?:il\s+)?(?:n(?:umero|\.|°)?|codice))?\s*[:\-–]?\s*([A-Z0-9][A-Z0-9\/.\-]{2,39})/iu',
            '/(?:RUNTS|Registro\s+Unico\s+Nazionale(?:\s+del\s+Terzo\s+Settore)?)\s*(?:n(?:umero|\.|°)?|repertorio|iscrizione|codice)?\s*[:\-–]?\s*([A-Z0-9][A-Z0-9\/.\-]{2,39})/iu',
            '/repertorio\s+(?:RUNTS|nazionale)?\s*[:\-–]?\s*([A-Z0-9][A-Z0-9\/.\-]{2,39})/iu',
            '/\bRUNTS\b[^0-9A-Z]{0,24}([0-9]{3,12}(?:[\/.\-][0-9A-Z]{1,12})?)/iu',
        ];
        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $text, $m)) {
                continue;
            }
            $code = trim((string) ($m[1] ?? ''), " \t\n\r\0\x0B:-–.");
            if ($this->isPlausibleRunts($code)) {
                return $code;
            }
        }
        return null;
    }

    private function isPlausibleRunts(string $code): bool
    {
        $code = trim($code);
        if ($code === '' || mb_strlen($code) < 3 || mb_strlen($code) > 40) {
            return false;
        }
        $lower = mb_strtolower($code);
        $blocked = [
            'nazionale', 'terzo', 'settore', 'registro', 'unico', 'iscrizione',
            'numero', 'repertorio', 'dell', 'associazione', 'runts', 'ets', 'aps', 'odv',
        ];
        if (in_array($lower, $blocked, true)) {
            return false;
        }
        // Real RUNTS refs almost always include a digit.
        return (bool) preg_match('/\d/', $code);
    }

    /**
     * Heuristic extraction of legal representative and board names (no AI).
     *
     * @return array<string, string>
     */
    private function extractOfficers(string $text, string $html): array
    {
        $out = [];

        $nameToken = '[A-ZÀ-Ö][A-Za-zÀ-ÖØ-öø-ÿ\'\-]+';
        $nameCapture = '(' . $nameToken . '(?:[ \t]+' . $nameToken . '){1,3})';

        $rolePatterns = [
            'president_name' => [
                '/(?:presidente[ \t]+e[ \t]+legale[ \t]+rappresentante|legale[ \t]+rappresentante|presidente(?:[ \t]+(?:dell[\'’]associazione|pro[ \t]*tempore))?)\s*[:\-–]\s*' . $nameCapture . '/iu',
                '/' . $nameCapture . '\s*[,:\-–]\s*(?:presidente(?:[ \t]+e[ \t]+legale[ \t]+rappresentante)?|legale[ \t]+rappresentante)\b/iu',
            ],
            'vice_president_name' => [
                '/(?:vice[\s\-]?presidente)\s*[:\-–]\s*' . $nameCapture . '/iu',
                '/' . $nameCapture . '\s*[,:\-–]\s*vice[\s\-]?presidente\b/iu',
            ],
            'secretary_name' => [
                '/(?:segretari[oa])\s*[:\-–]\s*' . $nameCapture . '/iu',
                '/' . $nameCapture . '\s*[,:\-–]\s*segretari[oa]\b/iu',
            ],
            'treasurer_name' => [
                '/(?:tesorier[ea])\s*[:\-–]\s*' . $nameCapture . '/iu',
                '/' . $nameCapture . '\s*[,:\-–]\s*tesorier[ea]\b/iu',
            ],
        ];

        $haystacks = [$text, $this->htmlToLooseText($html)];
        foreach ($rolePatterns as $key => $patterns) {
            foreach ($patterns as $pattern) {
                foreach ($haystacks as $hay) {
                    if (!preg_match($pattern, $hay, $m)) {
                        continue;
                    }
                    $name = $this->normalizePersonFullName((string) ($m[1] ?? ''));
                    if ($name !== null) {
                        $out[$key] = $name;
                        break 2;
                    }
                }
            }
        }

        $board = $this->extractBoardNames($text, $html, $out['president_name'] ?? null);
        if ($board !== []) {
            $out['board_names'] = implode(', ', $board);
        }

        return $out;
    }

    private function htmlToLooseText(string $html): string
    {
        $html = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $html = str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>', '</tr>', '</h1>', '</h2>', '</h3>', '</h4>'], "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_replace("/[ \t]+/", ' ', $text) ?? $text;
    }

    /** @return list<string> */
    private function extractBoardNames(string $text, string $html, ?string $presidentName): array
    {
        $names = [];
        $chunks = [];

        if (preg_match(
            '/(?:consiglio\s+direttivo|organi\s+(?:sociali|statutari)|direttivo|consiglieri)\s*[:\-–]?\s*([\s\S]{10,1200})/iu',
            $text,
            $m
        )) {
            $chunk = (string) $m[1];
            $chunk = preg_split(
                '/\b(?:revisori|collegio\s+dei\s+revisori|organi?\s+di\s+controllo|contatti|privacy|sede\s+legale|codice\s+fiscale|partita\s+iva|runts)\b/iu',
                $chunk
            )[0] ?? $chunk;
            $chunks[] = $chunk;
        }

        if (preg_match_all(
            '/<(?:h[1-6]|strong|b|p|div)[^>]*>[^<]{0,80}(?:consiglio\s+direttivo|direttivo|organi\s+sociali|consiglieri)[^<]{0,80}<\/(?:h[1-6]|strong|b|p|div)>(.{0,2500})/iu',
            $html,
            $blocks,
            PREG_SET_ORDER
        )) {
            foreach ($blocks as $block) {
                $chunks[] = $this->htmlToLooseText((string) ($block[1] ?? ''));
                if (preg_match_all('/<li\b[^>]*>(.*?)<\/li>/is', (string) ($block[1] ?? ''), $lis)) {
                    foreach ($lis[1] as $li) {
                        $line = trim(html_entity_decode(strip_tags((string) $li), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                        $line = preg_replace('/\s+/', ' ', $line) ?? $line;
                        $line = preg_replace('/\s*[\-–,|:]\s*(?:consigliere|presidente|vice[\s\-]?presidente|segretari[oa]|tesorier[ea]|membro).*$/iu', '', $line) ?? $line;
                        $name = $this->normalizePersonFullName($line);
                        if ($name !== null) {
                            $names[] = $name;
                        }
                    }
                }
            }
        }

        foreach ($chunks as $chunk) {
            foreach ($this->namesFromLooseChunk($chunk) as $name) {
                $names[] = $name;
            }
        }

        $presidentNorm = $presidentName !== null ? mb_strtolower($presidentName) : '';
        $unique = [];
        foreach ($names as $name) {
            $key = mb_strtolower($name);
            if ($presidentNorm !== '' && $key === $presidentNorm) {
                continue;
            }
            if (isset($unique[$key])) {
                continue;
            }
            $unique[$key] = $name;
            if (count($unique) >= 12) {
                break;
            }
        }
        return array_values($unique);
    }

    /** @return list<string> */
    private function namesFromLooseChunk(string $chunk): array
    {
        $out = [];
        // "Mario Rossi – Consigliere" / "Mario Rossi, consigliere"
        if (preg_match_all(
            '/([A-ZÀ-Ö][A-Za-zÀ-ÖØ-öø-ÿ\'\-]+(?:[ \t]+[A-ZÀ-Ö][A-Za-zÀ-ÖØ-öø-ÿ\'\-]+){1,3})[ \t]*(?:[\-–,|:][ \t]*)?(?:consigliere|membro(?:[ \t]+del[ \t]+direttivo)?)\b/iu',
            $chunk,
            $m,
            PREG_SET_ORDER
        )) {
            foreach ($m as $row) {
                $name = $this->normalizePersonFullName((string) ($row[1] ?? ''));
                if ($name !== null) {
                    $out[] = $name;
                }
            }
        }

        // Bullet-like lines that are just "Nome Cognome"
        foreach (preg_split('/[\n;•·]+/u', $chunk) ?: [] as $line) {
            $line = trim((string) $line);
            $line = preg_replace('/^[\-\*\d\.\)\s]+/u', '', $line) ?? $line;
            $line = preg_replace('/\s*[\-–,|:]\s*(?:consigliere|presidente|vice[\s\-]?presidente|segretari[oa]|tesorier[ea]).*$/iu', '', $line) ?? $line;
            $name = $this->normalizePersonFullName($line);
            if ($name !== null) {
                $out[] = $name;
            }
        }
        return $out;
    }

    private function normalizePersonFullName(string $raw): ?string
    {
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $raw = preg_replace('/\s+/u', ' ', trim($raw)) ?? '';
        $raw = trim($raw, " \t\n\r\0\x0B.,;:|-–—");
        if ($raw === '' || mb_strlen($raw) > 80) {
            return null;
        }
        if (preg_match('/\d|@|https?:|\.it\b|\.com\b/iu', $raw)) {
            return null;
        }

        $parts = preg_split('/\s+/u', $raw) ?: [];
        if (count($parts) < 2 || count($parts) > 4) {
            return null;
        }

        $stop = [
            'associazione', 'aps', 'odv', 'ets', 'onlus', 'presidente', 'vicepresidente', 'vice',
            'segretario', 'segretaria', 'tesoriere', 'tesoriera', 'consigliere', 'consigliera',
            'legale', 'rappresentante', 'direttivo', 'consiglio', 'membro', 'soci', 'socio',
            'dott', 'dottssa', 'sig', 'sigra', 'avv', 'prof', 'ing', 'the', 'and', 'del', 'della',
            'dei', 'degli', 'delle', 'di', 'e', 'il', 'la', 'lo', 'gli', 'un', 'una',
        ];
        $clean = [];
        foreach ($parts as $part) {
            $part = trim($part, ".,;:'\"“”‘’");
            if ($part === '') {
                continue;
            }
            $lower = mb_strtolower($part);
            if (in_array($lower, $stop, true)) {
                return null;
            }
            if (!preg_match('/^[A-Za-zÀ-ÖØ-öø-ÿ\'\-]+$/u', $part)) {
                return null;
            }
            // Title-case tokens (keep particles like De/Di if capitalized later)
            $clean[] = mb_strtoupper(mb_substr($part, 0, 1)) . mb_strtolower(mb_substr($part, 1));
        }
        if (count($clean) < 2) {
            return null;
        }
        return implode(' ', $clean);
    }

    /** @return array{city?:string,postal_code?:string,address?:string,house_number?:string} */
    private function extractAddress(string $text, string $html = ''): array
    {
        $candidates = [];

        $fromMaps = $this->extractAddressFromMapsLinks($html);
        if ($fromMaps !== []) {
            $candidates[] = $fromMaps;
        }

        $fromJson = $this->extractAddressFromJsonLd($html);
        if ($fromJson !== []) {
            $candidates[] = $fromJson;
        }

        $cleanText = $this->scrubAddressNoise($text);
        // Via/Viale/Piazza Nome 12, 00100 Roma
        if (preg_match(
            '/\b(via|viale|piazza|corso|largo|vicolo|piazzale|strada|contrada)\s+([A-Za-zÀ-ÖØ-öø-ÿ\'\.\s]{2,60}?)\s+(\d+[A-Za-z\/]*)\s*[, ]+\s*(\d{5})\s+([A-Za-zÀ-ÖØ-öø-ÿ\'\-]{2,40}(?:\s+[A-Za-zÀ-ÖØ-öø-ÿ\'\-]{2,40}){0,3})/iu',
            $cleanText,
            $m
        )) {
            $city = $this->sanitizeCityName($m[5]);
            if ($city !== null) {
                $candidates[] = [
                    'address' => trim($m[1] . ' ' . preg_replace('/\s+/', ' ', $m[2])),
                    'house_number' => trim($m[3]),
                    'postal_code' => $m[4],
                    'city' => $city,
                ];
            }
        }

        if (preg_match_all('/\b(\d{5})\s+([A-ZÀ-Ö][A-Za-zÀ-ÖØ-öø-ÿ\'\-]{1,40}(?:\s+[A-Za-zÀ-ÖØ-öø-ÿ\'\-]{2,40}){0,3})/u', $cleanText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $city = $this->sanitizeCityName($m[2]);
                if ($city === null) {
                    continue;
                }
                $candidates[] = [
                    'postal_code' => $m[1],
                    'city' => $city,
                ];
            }
        }

        return $this->pickBestAddress($candidates);
    }

    /**
     * @param list<array{city?:string,postal_code?:string,address?:string,house_number?:string}> $candidates
     * @return array{city?:string,postal_code?:string,address?:string,house_number?:string}
     */
    private function pickBestAddress(array $candidates): array
    {
        $best = [];
        $bestScore = -1;
        foreach ($candidates as $row) {
            $score = 0;
            if (!empty($row['address'])) {
                $score += 4;
            }
            if (!empty($row['house_number'])) {
                $score += 2;
            }
            if (!empty($row['postal_code'])) {
                $score += 2;
            }
            if (!empty($row['city'])) {
                $score += 3;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }
        return $best;
    }

    /** Remove map CTAs / UI chrome that often sits next to a CAP in scraped HTML. */
    private function scrubAddressNoise(string $text): string
    {
        $patterns = [
            '/\b(?:ottieni|apri|vedi|get|open|view)\s+indicazioni\b/iu',
            '/\bget\s+directions?\b/iu',
            '/\bindicazioni\s+stradali\b/iu',
            '/\bhow\s+to\s+get\s+here\b/iu',
            '/\bclick\s+(?:here|for)\b/iu',
            '/\bscopri\s+di\s+pi[uù]\b/iu',
            '/\bleggi\s+di\s+pi[uù]\b/iu',
            '/\bshare\s+on\b/iu',
            '/\bcookie(?:s)?\b/iu',
        ];
        $out = preg_replace($patterns, ' ', $text) ?? $text;
        return preg_replace('/\s+/', ' ', $out) ?? $out;
    }

    private function sanitizeCityName(string $raw): ?string
    {
        $city = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
        if ($city === '') {
            return null;
        }

        // Cut at first junk token if regex over-captured.
        if (preg_match(
            '/^(.*?)(?=\s+(?:ottieni|indicazioni|directions?|contatti|contacts?|email|telefono|phone|privacy|cookie|menu|home|chi\s+siamo|about)\b)/iu',
            $city,
            $m
        )) {
            $city = trim($m[1]);
        }

        // Drop trailing province codes: "Bolzano BZ" / "Bolzano (BZ)"
        $city = preg_replace('/\s*\(([A-Z]{2})\)\s*$/u', '', $city) ?? $city;
        $city = preg_replace('/\s+[A-Z]{2}\s*$/u', '', $city) ?? $city;
        $city = trim($city, " \t\n\r\0\x0B,;.-");

        if ($city === '' || mb_strlen($city, 'UTF-8') < 2 || mb_strlen($city, 'UTF-8') > 48) {
            return null;
        }
        if (preg_match('/\d/', $city)) {
            return null;
        }
        if ($this->isNonCityLabel($city)) {
            return null;
        }
        // Must look like a place name (letters, spaces, apostrophes, hyphens).
        if (!preg_match('/^[A-Za-zÀ-ÖØ-öø-ÿ][A-Za-zÀ-ÖØ-öø-ÿ\'\-\s]+$/u', $city)) {
            return null;
        }

        return $city;
    }

    private function isNonCityLabel(string $city): bool
    {
        $lower = mb_strtolower($city, 'UTF-8');
        $blocked = [
            'ottieni indicazioni',
            'get directions',
            'indicazioni stradali',
            'indicazioni',
            'directions',
            'contatti',
            'contacts',
            'contact',
            'email',
            'telefono',
            'phone',
            'privacy',
            'cookie',
            'cookies',
            'menu',
            'home',
            'chi siamo',
            'about us',
            'about',
            'scopri di più',
            'leggi di più',
            'click here',
            'clicca qui',
        ];
        foreach ($blocked as $bad) {
            if ($lower === $bad || str_contains($lower, $bad)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{city?:string,postal_code?:string,address?:string,house_number?:string} */
    private function extractAddressFromMapsLinks(string $html): array
    {
        if ($html === '') {
            return [];
        }
        $queries = [];
        if (preg_match_all(
            '/https?:\/\/(?:www\.)?(?:google\.[a-z.]+\/maps|maps\.google\.[a-z.]+|goo\.gl\/maps)[^"\'\s<>]*/iu',
            $html,
            $m
        )) {
            foreach ($m[0] as $url) {
                $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $q = '';
                if (preg_match('/[?&](?:q|query|daddr|destination)=([^&]+)/i', $url, $mm)) {
                    $q = urldecode(str_replace('+', ' ', $mm[1]));
                } elseif (preg_match('/\/maps\/place\/([^\/\'"\s]+)/i', $url, $mm)) {
                    $q = urldecode(str_replace('+', ' ', $mm[1]));
                } elseif (preg_match('/\/maps\/search\/([^\/\'"\s]+)/i', $url, $mm)) {
                    $q = urldecode(str_replace('+', ' ', $mm[1]));
                }
                $q = trim($q);
                if ($q !== '' && !preg_match('/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/', $q)) {
                    $queries[] = $q;
                }
            }
        }
        // Wix / generic map iframes sometimes encode the address in the title/aria label nearby.
        if (preg_match_all('/(?:aria-label|title)=["\']([^"\']*(?:via|viale|piazza|corso)\s[^"\']{5,120})["\']/iu', $html, $m)) {
            foreach ($m[1] as $label) {
                $queries[] = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        foreach ($queries as $q) {
            $parsed = $this->extractAddress($this->scrubAddressNoise($q), '');
            if ($parsed !== []) {
                return $parsed;
            }
        }
        return [];
    }

    /** @return array{city?:string,postal_code?:string,address?:string,house_number?:string} */
    private function extractAddressFromJsonLd(string $html): array
    {
        if ($html === '' || !preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $blocks)) {
            return [];
        }
        foreach ($blocks[1] as $json) {
            $data = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (!is_array($data)) {
                continue;
            }
            $nodes = isset($data[0]) ? $data : [$data];
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $addr = $node['address'] ?? ($node['location']['address'] ?? null);
                if (!is_array($addr)) {
                    continue;
                }
                $out = [];
                $street = trim((string) ($addr['streetAddress'] ?? ''));
                if ($street !== '' && preg_match('/^(.*?)\s+(\d+[A-Za-z\/]*)$/u', $street, $m)) {
                    $out['address'] = trim($m[1]);
                    $out['house_number'] = trim($m[2]);
                } elseif ($street !== '') {
                    $out['address'] = $street;
                }
                $cap = trim((string) ($addr['postalCode'] ?? ''));
                if (preg_match('/^\d{5}$/', $cap)) {
                    $out['postal_code'] = $cap;
                }
                $city = $this->sanitizeCityName((string) ($addr['addressLocality'] ?? ''));
                if ($city !== null) {
                    $out['city'] = $city;
                }
                if ($out !== []) {
                    return $out;
                }
            }
        }
        return [];
    }
}
