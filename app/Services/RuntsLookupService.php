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
    public function lookup(string $repertory): array
    {
        $number = $this->normalizeRepertory($repertory);
        if ($number === '') {
            return ['ok' => false, 'error' => __('setup.runts_need_number')];
        }

        $ensured = $this->ensureLists();
        if (empty($ensured['ok'])) {
            return ['ok' => false, 'error' => (string) ($ensured['error'] ?? __('setup.runts_fail'))];
        }

        $activePath = $this->listPath('iscritti');
        $cancelledPath = $this->listPath('cancellati');

        $active = is_file($activePath) ? $this->findInXlsx($activePath, $number) : null;
        if ($active !== null) {
            return $this->hydrate($active, false);
        }

        $cancelled = is_file($cancelledPath) ? $this->findInXlsx($cancelledPath, $number) : null;
        if ($cancelled !== null) {
            $result = $this->hydrate($cancelled, true);
            $cancelMsg = __('setup.runts_cancelled', [
                'name' => (string) ($result['fields']['name'] ?? $cancelled['denominazione'] ?? ''),
                'date' => (string) ($cancelled['data_cancellazione'] ?? ''),
                'reason' => (string) ($cancelled['tipo_cancellazione'] ?? ''),
            ]);
            $result['warning'] = trim((string) ($result['warning'] ?? '')) !== ''
                ? $cancelMsg . ' ' . $result['warning']
                : $cancelMsg;
            return $result;
        }

        return ['ok' => false, 'error' => __('setup.runts_not_found', ['number' => $number])];
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

    /** @return array{ok:bool,error?:string} */
    private function ensureLists(): array
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
            && is_file($iscritti)
            && is_file($cancellati)
            && filesize($iscritti) > 100000
            && filesize($cancellati) > 10000;
        if ($fresh) {
            return ['ok' => true];
        }

        $downloaded = $this->downloadLists();
        if (!empty($downloaded['ok'])) {
            return ['ok' => true];
        }
        if (is_file($iscritti) && filesize($iscritti) > 100000) {
            return ['ok' => true];
        }
        return $downloaded;
    }

    /** @return array{ok:bool,error?:string} */
    private function downloadLists(): array
    {
        $cookie = $this->cacheDir() . '/cookies.txt';
        @unlink($cookie);

        $page = $this->http('GET', self::LIST_URL, null, $cookie);
        if ($page['status'] !== 200 || trim($page['body']) === '') {
            return ['ok' => false, 'error' => __('setup.runts_fail')];
        }

        $iscrittiBtn = $this->findDownloadButton($page['body'], 'Enti iscritti (formato Excel)');
        $cancellatiBtn = $this->findDownloadButton($page['body'], 'Enti cancellati (formato Excel)');
        if ($iscrittiBtn === '' || $cancellatiBtn === '') {
            return ['ok' => false, 'error' => __('setup.runts_fail')];
        }

        $iscritti = $this->postDownload($page['body'], $iscrittiBtn, $cookie);
        if (empty($iscritti['ok'])) {
            return ['ok' => false, 'error' => __('setup.runts_fail')];
        }

        $page2 = $this->http('GET', self::LIST_URL, null, $cookie);
        if ($page2['status'] !== 200 || trim($page2['body']) === '') {
            return ['ok' => false, 'error' => __('setup.runts_fail')];
        }
        $cancellatiBtn = $this->findDownloadButton($page2['body'], 'Enti cancellati (formato Excel)') ?: $cancellatiBtn;
        $cancellati = $this->postDownload($page2['body'], $cancellatiBtn, $cookie);
        if (empty($cancellati['ok'])) {
            return ['ok' => false, 'error' => __('setup.runts_fail')];
        }

        file_put_contents($this->listPath('iscritti'), $iscritti['body']);
        file_put_contents($this->listPath('cancellati'), $cancellati['body']);
        file_put_contents($this->cacheDir() . '/meta.json', json_encode([
            'fetched_at' => time(),
            'iscritti_name' => $iscritti['filename'] ?? '',
            'cancellati_name' => $cancellati['filename'] ?? '',
        ], JSON_UNESCAPED_UNICODE));

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
     * @return array{ok:bool,body?:string,filename?:string}
     */
    private function postDownload(string $html, string $buttonName, string $cookieFile): array
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
        $res = $this->http('POST', self::LIST_URL, $fields, $cookieFile);
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
     * @return array<string, string>|null
     */
    private function findInXlsx(string $path, string $number): ?array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $stream = $zip->getStream('xl/worksheets/sheet1.xml');
        if (!is_resource($stream)) {
            $zip->close();
            return null;
        }

        $needle = '<is><t>' . $number . '</t></is></c>';
        $buffer = '';
        $found = null;
        while (!feof($stream)) {
            $chunk = fread($stream, 512 * 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer .= $chunk;
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
                // Skip this occurrence (wrong column or parse) and keep scanning.
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
     * @return array{status:int,body:string,headers:string}
     */
    private function http(string $method, string $url, ?array $fields, string $cookieFile): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'headers' => ''];
        }
        $ch = curl_init($url);
        $headers = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 90,
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

    private function cacheDir(): string
    {
        return storage_path('runts');
    }

    private function listPath(string $kind): string
    {
        return $this->cacheDir() . '/' . $kind . '.xlsx';
    }
}
