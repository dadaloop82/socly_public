<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Setup\AssociationLegalForms;
use ZipArchive;

/**
 * Looks up an ETS by RUNTS repertory number in the official Ministero del Lavoro Excel lists.
 */
final class RuntsLookupService
{
    private const LIST_URL = 'https://servizi.lavoro.gov.it/runts/it-it/Lista-enti';
    private const CACHE_TTL = 20 * 3600;
    private const TIMEOUT_SECONDS = 120;
    private const UA = 'Mozilla/5.0 (compatible; SOCLY/1.0; +https://www.socly.it/)';

    /**
     * @return array{
     *   ok:bool,
     *   error?:string,
     *   cancelled?:bool,
     *   warning?:string,
     *   record?:array<string,string>,
     *   fields?:array<string,string>
     * }
     */
    /**
     * @param callable(array<string, mixed>):void|null $emit
     */
    public function lookup(string $repertory, ?callable $emit = null): array
    {
        $number = $this->normalizeRepertory($repertory);
        if ($number === '') {
            return ['ok' => false, 'error' => __('setup.runts_need_number')];
        }

        $emit = $emit ?? static function (): void {};
        $started = microtime(true);
        $emit([
            'type' => 'start',
            'number' => $number,
            'timeout_seconds' => self::TIMEOUT_SECONDS,
        ]);
        $emit(['type' => 'progress', 'phase' => 'connect', 'percent' => 4]);

        $ensured = $this->ensureLists($emit);
        if (empty($ensured['ok'])) {
            return ['ok' => false, 'error' => (string) ($ensured['error'] ?? __('setup.runts_fail'))];
        }

        $activePath = $this->listPath('iscritti');
        $cancelledPath = $this->listPath('cancellati');

        $emit(['type' => 'progress', 'phase' => 'search_active', 'percent' => 58, 'number' => $number]);
        $active = is_file($activePath)
            ? $this->findInXlsx($activePath, $number, $emit, 'search_active', 58, 88)
            : null;
        if ($active !== null) {
            $emit(['type' => 'progress', 'phase' => 'apply', 'percent' => 96]);
            $result = $this->hydrate($active, false);
            $result['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
            return $result;
        }

        $emit(['type' => 'progress', 'phase' => 'search_cancelled', 'percent' => 90, 'number' => $number]);
        $cancelled = is_file($cancelledPath)
            ? $this->findInXlsx($cancelledPath, $number, $emit, 'search_cancelled', 90, 97)
            : null;
        if ($cancelled !== null) {
            $emit(['type' => 'progress', 'phase' => 'apply', 'percent' => 98]);
            $result = $this->hydrate($cancelled, true);
            $cancelMsg = __('setup.runts_cancelled', [
                'name' => (string) ($result['fields']['name'] ?? $cancelled['denominazione'] ?? ''),
                'date' => (string) ($cancelled['data_cancellazione'] ?? ''),
                'reason' => (string) ($cancelled['tipo_cancellazione'] ?? ''),
            ]);
            $result['warning'] = trim((string) ($result['warning'] ?? '')) !== ''
                ? $cancelMsg . ' ' . $result['warning']
                : $cancelMsg;
            $result['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
            return $result;
        }

        return [
            'ok' => false,
            'error' => __('setup.runts_not_found', ['number' => $number]),
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    public function normalizeRepertory(string $value): string
    {
        return preg_replace('/\D+/', '', trim($value)) ?? '';
    }

    /**
     * @param array<string, string> $row
     * @return array{ok:bool,cancelled:bool,record:array<string,string>,fields:array<string,string>,warning?:string}
     */
    private function hydrate(array $row, bool $cancelled): array
    {
        $denomination = $this->prettyName((string) ($row['denominazione'] ?? ''));
        $section = trim((string) ($row['sezione'] ?? ''));
        $legal = AssociationLegalForms::fromRuntsSection($section, $denomination) ?? '';
        $city = $this->prettyName((string) ($row['comune_sede_legale'] ?? ''));
        $fields = array_filter([
            'runts' => (string) ($row['repertorio'] ?? ''),
            'name' => $denomination,
            'legal_name' => $legal,
            'fiscal_code' => strtoupper(preg_replace('/\s+/', '', (string) ($row['codice_fiscale'] ?? '')) ?? ''),
            'city' => $city,
            'president_name' => $this->personFromRunts((string) ($row['legale_rappresentante'] ?? '')),
            'section' => $section,
        ], static fn ($v) => is_string($v) && trim($v) !== '');

        return [
            'ok' => true,
            'cancelled' => $cancelled,
            'record' => $row,
            'fields' => $fields,
            'warning' => ($section !== '' && $legal === '')
                ? __('setup.runts_legal_unknown', ['section' => $section])
                : '',
        ];
    }

    private function prettyName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            return '';
        }
        $titled = mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

        return preg_replace_callback(
            '/\b(Aps|Odv|Ets|Onlus|Coop|Asd|Ssd)\b/u',
            static fn (array $m): string => mb_strtoupper($m[1], 'UTF-8'),
            $titled
        ) ?? $titled;
    }

    /** RUNTS lists "COGNOME NOME". */
    private function personFromRunts(string $raw): string
    {
        $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        if ($raw === '') {
            return '';
        }
        $parts = preg_split('/\s+/u', mb_convert_case(mb_strtolower($raw, 'UTF-8'), MB_CASE_TITLE, 'UTF-8')) ?: [];
        if (count($parts) < 2) {
            return implode(' ', $parts);
        }
        $last = array_shift($parts);
        return trim(implode(' ', $parts) . ' ' . $last);
    }

    /**
     * @param callable(array<string, mixed>):void $emit
     * @return array{ok:bool,error?:string}
     */
    private function ensureLists(callable $emit): array
    {
        $dir = $this->cacheDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => __('setup.runts_fail')];
        }

        $metaFile = $dir . '/meta.json';
        $meta = [];
        if (is_file($metaFile)) {
            $decoded = json_decode((string) file_get_contents($metaFile), true);
            $meta = is_array($decoded) ? $decoded : [];
        }
        $fetchedAt = (int) ($meta['fetched_at'] ?? 0);
        $iscritti = $this->listPath('iscritti');
        $cancellati = $this->listPath('cancellati');
        $fresh = $fetchedAt > 0
            && (time() - $fetchedAt) < self::CACHE_TTL
            && $this->isValidList($iscritti, 100000)
            && $this->isValidList($cancellati, 10000);
        if ($fresh) {
            $emit(['type' => 'progress', 'phase' => 'lists_ready', 'percent' => 20]);
            return ['ok' => true];
        }

        $downloaded = $this->downloadLists($emit);
        if (!empty($downloaded['ok'])) {
            return ['ok' => true];
        }
        if ($this->isValidList($iscritti, 100000)) {
            $emit(['type' => 'progress', 'phase' => 'lists_ready', 'percent' => 20]);
            return ['ok' => true];
        }
        return $downloaded;
    }

    /**
     * @param callable(array<string, mixed>):void $emit
     * @return array{ok:bool,error?:string}
     */
    private function downloadLists(callable $emit): array
    {
        $cookie = $this->cacheDir() . '/cookies.txt';
        @unlink($cookie);

        $emit(['type' => 'progress', 'phase' => 'connect', 'percent' => 6]);
        $page = $this->http('GET', self::LIST_URL, null, $cookie, 20, null);
        if ($page['status'] !== 200 || trim($page['body']) === '') {
            return ['ok' => false, 'error' => __('setup.runts_fail')];
        }

        $iscrittiBtn = $this->findDownloadButton($page['body'], 'Enti iscritti (formato Excel)');
        $cancellatiBtn = $this->findDownloadButton($page['body'], 'Enti cancellati (formato Excel)');
        if ($iscrittiBtn === '' || $cancellatiBtn === '') {
            return ['ok' => false, 'error' => __('setup.runts_fail')];
        }

        $emit(['type' => 'progress', 'phase' => 'download_active', 'percent' => 10]);
        $lastPct = 10;
        $iscritti = $this->postDownload($page['body'], $iscrittiBtn, $cookie, static function (float $ratio) use ($emit, &$lastPct): void {
            $pct = 10 + (int) round($ratio * 32);
            if ($pct <= $lastPct) {
                return;
            }
            $lastPct = $pct;
            $emit(['type' => 'progress', 'phase' => 'download_active', 'percent' => $pct]);
        });
        if (empty($iscritti['ok']) || !$this->storeList('iscritti', (string) ($iscritti['body'] ?? ''))) {
            return ['ok' => false, 'error' => __('setup.runts_fail')];
        }

        $emit(['type' => 'progress', 'phase' => 'download_cancelled', 'percent' => 44]);
        $page2 = $this->http('GET', self::LIST_URL, null, $cookie, 20, null);
        if ($page2['status'] !== 200 || trim($page2['body']) === '') {
            return ['ok' => false, 'error' => __('setup.runts_fail')];
        }
        $cancellatiBtn = $this->findDownloadButton($page2['body'], 'Enti cancellati (formato Excel)') ?: $cancellatiBtn;
        $lastPct = 44;
        $cancellati = $this->postDownload($page2['body'], $cancellatiBtn, $cookie, static function (float $ratio) use ($emit, &$lastPct): void {
            $pct = 44 + (int) round($ratio * 12);
            if ($pct <= $lastPct) {
                return;
            }
            $lastPct = $pct;
            $emit(['type' => 'progress', 'phase' => 'download_cancelled', 'percent' => $pct]);
        });
        if (empty($cancellati['ok']) || !$this->storeList('cancellati', (string) ($cancellati['body'] ?? ''))) {
            return ['ok' => false, 'error' => __('setup.runts_fail')];
        }

        file_put_contents($this->cacheDir() . '/meta.json', json_encode([
            'fetched_at' => time(),
            'iscritti_name' => $iscritti['filename'] ?? '',
            'cancellati_name' => $cancellati['filename'] ?? '',
        ], JSON_UNESCAPED_UNICODE));

        $emit(['type' => 'progress', 'phase' => 'lists_ready', 'percent' => 58]);
        return ['ok' => true];
    }

    private function findDownloadButton(string $html, string $title): string
    {
        $quoted = preg_quote($title, '/');
        if (preg_match(
            '/' . $quoted . '.*?name="([^"]*btnScaricaDoc)"/is',
            $html,
            $m
        )) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    /**
     * @param callable(float):void|null $onProgress
     * @return array{ok:bool,body?:string,filename?:string}
     */
    private function postDownload(string $html, string $buttonName, string $cookieFile, ?callable $onProgress = null): array
    {
        $fields = [
            '__EVENTTARGET' => '',
            '__EVENTARGUMENT' => '',
            '__VIEWSTATE' => $this->hiddenValue($html, '__VIEWSTATE'),
            '__VIEWSTATEGENERATOR' => $this->hiddenValue($html, '__VIEWSTATEGENERATOR'),
            '__VIEWSTATEENCRYPTED' => $this->hiddenValue($html, '__VIEWSTATEENCRYPTED'),
            '__EVENTVALIDATION' => $this->hiddenValue($html, '__EVENTVALIDATION'),
            $buttonName => 'Scarica',
        ];
        $res = $this->http('POST', self::LIST_URL, $fields, $cookieFile, 55, $onProgress);
        if ($res['status'] !== 200 || strlen($res['body']) < 1000) {
            return ['ok' => false];
        }
        if (!str_starts_with($res['body'], 'PK')) {
            return ['ok' => false];
        }
        $filename = '';
        if (preg_match('/filename=([^;\\r\\n]+)/i', $res['headers'], $m)) {
            $filename = trim($m[1], " \t\"'");
        }
        return ['ok' => true, 'body' => $res['body'], 'filename' => $filename];
    }

    private function hiddenValue(string $html, string $name): string
    {
        if (preg_match(
            '/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/i',
            $html,
            $m
        )) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    /**
     * @param callable(array<string, mixed>):void $emit
     * @return array<string, string>|null
     */
    private function findInXlsx(
        string $path,
        string $number,
        callable $emit,
        string $phase,
        int $pctFrom,
        int $pctTo
    ): ?array {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $stat = $zip->statName('xl/worksheets/sheet1.xml');
        $total = (int) ($stat['size'] ?? 0);
        $stream = $zip->getStream('xl/worksheets/sheet1.xml');
        if (!is_resource($stream)) {
            $zip->close();
            return null;
        }

        $needle = '<is><t>' . $number . '</t></is></c>';
        $buffer = '';
        $found = null;
        $read = 0;
        $lastEmit = 0.0;
        while (!feof($stream)) {
            $chunk = fread($stream, 256 * 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $read += strlen($chunk);
            $buffer .= $chunk;
            $now = microtime(true);
            if ($total > 0 && ($now - $lastEmit) >= 0.3) {
                $ratio = min(1, $read / $total);
                $emit([
                    'type' => 'progress',
                    'phase' => $phase,
                    'percent' => $pctFrom + (int) round(($pctTo - $pctFrom) * $ratio),
                    'number' => $number,
                ]);
                $lastEmit = $now;
            }
            $pos = strpos($buffer, $needle);
            if ($pos !== false) {
                $rowStart = strrpos(substr($buffer, 0, $pos), '<row ');
                $rowEnd = strpos($buffer, '</row>', $pos);
                if ($rowStart !== false && $rowEnd !== false) {
                    $rowXml = substr($buffer, $rowStart, $rowEnd - $rowStart);
                    if (preg_match('/<c r="B\d+"[^>]*>' . preg_quote($needle, '/') . '/', $rowXml)) {
                        $inner = $rowXml;
                        if (preg_match('/<row r="\d+">(.*)$/s', $rowXml, $rowMatch)) {
                            $inner = $rowMatch[1];
                        }
                        $parsed = $this->parseRow($inner);
                        if (($parsed['repertorio'] ?? '') === $number) {
                            $found = $parsed;
                            break;
                        }
                    }
                }
                $buffer = substr($buffer, $pos + strlen($needle));
                continue;
            }
            if (strlen($buffer) > 1_200_000) {
                $buffer = substr($buffer, -80_000);
            }
        }
        fclose($stream);
        $zip->close();
        return $found;
    }

    /**
     * @return array<string, string>
     */
    private function parseRow(string $rowXml): array
    {
        $map = [
            'A' => 'codice_fiscale',
            'B' => 'repertorio',
            'C' => 'denominazione',
            'D' => 'sezione',
            'E' => 'legale_rappresentante',
            'F' => 'rete',
            'G' => 'comune_sede_legale',
            'H' => 'provincia_sede_legale',
            'I' => 'cinque_per_mille',
            'J' => 'data_iscrizione',
            'K' => 'tipo_cancellazione',
            'L' => 'data_cancellazione',
        ];
        $out = [];
        if (preg_match_all(
            '/<c r="([A-Z]+)\d+"[^>]*>(?:<is><t(?: xml:space="preserve")?>(.*?)<\/t><\/is>)?/s',
            $rowXml,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $col = $m[1];
                $val = html_entity_decode(str_replace('&#xd;&#xa;', ' ', $m[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $key = $map[$col] ?? $col;
                $out[$key] = trim($val);
            }
        }
        return $out;
    }

    /**
     * @param array<string, string>|null $fields
     * @param callable(float):void|null $onProgress
     * @return array{status:int,body:string,headers:string}
     */
    private function http(
        string $method,
        string $url,
        ?array $fields,
        string $cookieFile,
        int $timeout,
        ?callable $onProgress
    ): array {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'headers' => ''];
        }
        $ch = curl_init($url);
        $headers = '';
        $lastProgress = 0.0;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => max(8, $timeout),
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$headers): int {
                $headers .= $header;
                return strlen($header);
            },
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/octet-stream,*/*',
            ],
        ]);
        if ($onProgress !== null) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static function ($ch, $downloadSize, $downloaded) use ($onProgress, &$lastProgress): int {
                if ($downloadSize <= 0) {
                    return 0;
                }
                $ratio = min(1.0, (float) $downloaded / (float) $downloadSize);
                if (($ratio - $lastProgress) < 0.02 && $ratio < 1) {
                    return 0;
                }
                $lastProgress = $ratio;
                $onProgress($ratio);
                return 0;
            });
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields ?? []));
            curl_setopt($ch, CURLOPT_REFERER, self::LIST_URL);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'headers' => $headers,
        ];
    }

    private function storeList(string $kind, string $body): bool
    {
        if ($body === '' || !str_starts_with($body, 'PK')) {
            return false;
        }
        $path = $this->listPath($kind);
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $body) === false) {
            return false;
        }
        if (!$this->isValidList($tmp, 1000)) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    private function isValidList(string $path, int $minBytes): bool
    {
        if (!is_file($path) || filesize($path) < $minBytes) {
            return false;
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        $ok = $zip->statName('xl/worksheets/sheet1.xml') !== false;
        $zip->close();
        return $ok;
    }

    private function cacheDir(): string
    {
        return storage_path('runts');
    }

    private function listPath(string $kind): string
    {
        return $this->cacheDir() . '/' . $kind . '.xlsx';
    }
}
