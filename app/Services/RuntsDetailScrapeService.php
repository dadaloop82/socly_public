<?php

declare(strict_types=1);

namespace Socly\Services;

/**
 * Scrapes the public RUNTS "Ricerca enti" detail page for one repertory number:
 * anagraphic fields, legal people, and downloadable Atti/Documenti PDFs.
 */
final class RuntsDetailScrapeService
{
    private const SEARCH_URL = 'https://servizi.lavoro.gov.it/runts/it-it/Ricerca-enti';
    private const UA = 'Mozilla/5.0 (compatible; SOCLY/1.0; +https://www.socly.it/)';
    private const TIMEOUT_SECONDS = 90;

    /**
     * @param callable(array<string, mixed>):void|null $emit
     * @return array{
     *   ok:bool,
     *   error?:string,
     *   fields?:array<string,string>,
     *   detail?:array<string,mixed>,
     *   documents?:list<array{title:string,code:string,date:string,filename:string,mime:string,tmp_path:string}>,
     *   warning?:string
     * }
     */
    public function fetch(string $repertory, ?callable $emit = null): array
    {
        $number = preg_replace('/\D+/', '', trim($repertory)) ?? '';
        if ($number === '') {
            return ['ok' => false, 'error' => __('setup.runts_need_number')];
        }

        $emit = $emit ?? static function (): void {};
        @set_time_limit(self::TIMEOUT_SECONDS + 180);

        $cookie = sys_get_temp_dir() . '/socly-runts-detail-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.txt';
        $tmpDocs = [];
        try {
            $emit(['type' => 'progress', 'phase' => 'detail', 'percent' => 70, 'number' => $number]);
            $opened = $this->openDetail($number, $cookie);
            if (empty($opened['ok'])) {
                return [
                    'ok' => false,
                    'error' => (string) ($opened['error'] ?? __('setup.runts_detail_fail')),
                ];
            }

            $html = (string) $opened['html'];
            $detailUrl = (string) $opened['url'];
            $fields = $this->extractFields($html, $number);
            $detail = $this->extractDetailMeta($html, $fields);
            $docRows = $this->listDocumentRows($html);

            $emit([
                'type' => 'progress',
                'phase' => 'docs',
                'percent' => 78,
                'number' => $number,
                'docs_total' => count($docRows),
            ]);

            $documents = [];
            $failed = 0;
            $total = max(1, count($docRows));
            foreach ($docRows as $i => $row) {
                // Re-open detail after each download (ASP.NET leaves the Ente page).
                if ($i > 0) {
                    $opened = $this->openDetail($number, $cookie);
                    if (empty($opened['ok'])) {
                        $failed++;
                        continue;
                    }
                    $html = (string) $opened['html'];
                    $detailUrl = (string) $opened['url'];
                }

                $pct = 78 + (int) round((($i + 1) / $total) * 16);
                $emit([
                    'type' => 'progress',
                    'phase' => 'docs',
                    'percent' => min(94, $pct),
                    'number' => $number,
                    'docs_current' => $i + 1,
                    'docs_total' => count($docRows),
                    'doc_title' => $row['title'],
                ]);

                $downloaded = $this->downloadDocument($html, $detailUrl, $row['button'], $cookie);
                if (empty($downloaded['ok'])) {
                    $failed++;
                    continue;
                }

                $tmp = sys_get_temp_dir() . '/socly-runts-doc-' . bin2hex(random_bytes(8)) . '.pdf';
                if (@file_put_contents($tmp, (string) $downloaded['body']) === false) {
                    $failed++;
                    continue;
                }
                @chmod($tmp, 0664);
                $tmpDocs[] = $tmp;
                $documents[] = [
                    'title' => $row['title'],
                    'code' => $row['code'],
                    'date' => $row['date'],
                    'filename' => (string) ($downloaded['filename'] ?: ($row['title'] . '.pdf')),
                    'mime' => (string) ($downloaded['mime'] ?: 'application/pdf'),
                    'tmp_path' => $tmp,
                ];
            }

            $warning = '';
            if ($docRows !== [] && $documents === []) {
                $warning = __('setup.runts_docs_fail');
            } elseif ($failed > 0) {
                $warning = __('setup.runts_docs_partial', ['failed' => (string) $failed]);
            }

            return [
                'ok' => true,
                'fields' => $fields,
                'detail' => $detail,
                'documents' => $documents,
                'warning' => $warning,
            ];
        } catch (\Throwable $e) {
            foreach ($tmpDocs as $path) {
                @unlink($path);
            }
            return ['ok' => false, 'error' => __('setup.runts_detail_fail')];
        } finally {
            @unlink($cookie);
        }
    }

    /**
     * @return array{ok:bool,html?:string,url?:string,error?:string}
     */
    private function openDetail(string $number, string $cookie): array
    {
        $page = $this->http('GET', self::SEARCH_URL, null, $cookie, 30);
        if ($page['status'] !== 200 || trim($page['body']) === '') {
            return ['ok' => false, 'error' => __('setup.runts_detail_fail')];
        }

        $payload = $this->formPayload($page['body']);
        $payload['dnn$ctr446$View$txtNumeroRepertorio'] = $number;
        $payload['dnn$ctr446$View$txtDenominazione'] = '';
        $payload['dnn$ctr446$View$txtCodiceFiscale'] = $payload['dnn$ctr446$View$txtCodiceFiscale'] ?? '';
        $payload['dnn$ctr446$View$txtComune'] = $payload['dnn$ctr446$View$txtComune'] ?? '';
        $payload['dnn$ctr446$View$btnRicercaEnti'] = 'Cerca';
        $payload['__EVENTTARGET'] = '';
        $payload['__EVENTARGUMENT'] = '';

        $results = $this->http('POST', self::SEARCH_URL, $payload, $cookie, 45);
        if ($results['status'] !== 200 || trim($results['body']) === '') {
            return ['ok' => false, 'error' => __('setup.runts_detail_fail')];
        }

        $btn = $this->firstDetailButton($results['body']);
        if ($btn === '') {
            return ['ok' => false, 'error' => __('setup.runts_not_found', ['number' => $number])];
        }

        $payload2 = $this->formPayload($results['body']);
        unset($payload2['dnn$ctr446$View$btnRicercaEnti']);
        $payload2[$btn] = 'Dettaglio';
        $payload2['__EVENTTARGET'] = '';
        $payload2['__EVENTARGUMENT'] = '';

        $detail = $this->http('POST', self::SEARCH_URL, $payload2, $cookie, 45);
        if ($detail['status'] !== 200 || trim($detail['body']) === '') {
            return ['ok' => false, 'error' => __('setup.runts_detail_fail')];
        }
        if (!str_contains($detail['effective_url'], '/Ente') && !$this->spanText($detail['body'], 'dnn_ctr448_View_spnRepertorio')) {
            return ['ok' => false, 'error' => __('setup.runts_detail_fail')];
        }

        return [
            'ok' => true,
            'html' => $detail['body'],
            'url' => $detail['effective_url'] !== '' ? $detail['effective_url'] : self::SEARCH_URL . '/Ente',
        ];
    }

    /**
     * @return list<array{title:string,code:string,date:string,button:string}>
     */
    private function listDocumentRows(string $html): array
    {
        $rows = [];
        if (!preg_match_all(
            '/<tr[^>]*>\s*<td[^>]*>(.*?)<\/td>\s*<td[^>]*>(.*?)<\/td>\s*<td[^>]*>(.*?)<\/td>\s*<td[^>]*>.*?name="([^"]*btnDownload)"/is',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            return [];
        }
        foreach ($matches as $m) {
            $title = $this->cleanText($m[1]);
            $button = html_entity_decode($m[4], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($title === '' || $button === '') {
                continue;
            }
            $rows[] = [
                'title' => $title,
                'code' => $this->cleanText($m[2]),
                'date' => $this->cleanText($m[3]),
                'button' => $button,
            ];
        }
        return $rows;
    }

    /**
     * @return array{ok:bool,body?:string,filename?:string,mime?:string}
     */
    private function downloadDocument(string $html, string $detailUrl, string $buttonName, string $cookie): array
    {
        $payload = $this->formPayload($html);
        // ImageButton postback
        $payload[$buttonName . '.x'] = '1';
        $payload[$buttonName . '.y'] = '1';
        $payload['__EVENTTARGET'] = '';
        $payload['__EVENTARGUMENT'] = '';

        $res = $this->http('POST', $detailUrl, $payload, $cookie, 120);
        if ($res['status'] !== 200 || strlen($res['body']) < 100) {
            return ['ok' => false];
        }
        if (!str_starts_with($res['body'], '%PDF')) {
            // Some gateways send octet-stream without PDF magic at once — still accept if CD says pdf.
            $cd = $res['headers'];
            if (!preg_match('/filename[^;=]*=([^;\\r\\n]+)/i', $cd) && !str_contains(strtolower($cd), 'pdf')) {
                return ['ok' => false];
            }
        }

        $filename = '';
        if (preg_match('/filename\*=(?:UTF-8\'\')?([^;\\r\\n]+)/i', $res['headers'], $m)
            || preg_match('/filename=([^;\\r\\n]+)/i', $res['headers'], $m)
        ) {
            $filename = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\"'");
        }
        $mime = 'application/pdf';
        if (preg_match('/Content-Type:\s*([^\r\n;]+)/i', $res['headers'], $m)) {
            $mime = trim($m[1]);
        }
        if (!str_contains(strtolower($mime), 'pdf') && str_starts_with($res['body'], '%PDF')) {
            $mime = 'application/pdf';
        }

        return [
            'ok' => true,
            'body' => $res['body'],
            'filename' => $filename !== '' ? $filename : 'documento-runts.pdf',
            'mime' => $mime,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extractFields(string $html, string $number): array
    {
        $name = $this->prettyName($this->spanText($html, 'dnn_ctr448_View_spnDenominazione'));
        $fiscal = strtoupper(preg_replace('/\s+/', '', $this->spanText($html, 'dnn_ctr448_View_spnCodiceFiscale')) ?? '');
        $section = trim($this->spanText($html, 'dnn_ctr448_View_spnSezione'));
        $legal = \Socly\Setup\AssociationLegalForms::fromRuntsSection($section, $name) ?? '';
        $city = $this->prettyName($this->spanText($html, 'dnn_ctr448_View_spnComuneSL'));
        $province = strtoupper(preg_replace('/[^A-Za-z]/', '', $this->spanText($html, 'dnn_ctr448_View_spnProvinciaSL')) ?? '');
        $address = $this->prettyName($this->spanText($html, 'dnn_ctr448_View_spnIndirizzoSL'));
        $house = trim($this->spanText($html, 'dnn_ctr448_View_spnCivicoSL'));
        $cap = preg_replace('/\D+/', '', $this->spanText($html, 'dnn_ctr448_View_spnCAP_SL')) ?? '';
        $pec = strtolower(trim($this->spanText($html, 'dnn_ctr448_View_spnEmailPEC')));
        $website = $this->normalizeWebsite($this->spanText($html, 'dnn_ctr448_View_spnSitoInternet'));
        $president = $this->extractPresident($html);

        $fields = array_filter([
            'runts' => $this->spanText($html, 'dnn_ctr448_View_spnRepertorio') ?: $number,
            'name' => $name,
            'legal_name' => $legal,
            'fiscal_code' => $fiscal,
            'city' => $city,
            'province' => $province,
            'address' => $address,
            'house_number' => $house,
            'postal_code' => $cap,
            'pec' => $pec,
            'website' => $website,
            'president_name' => $president,
            'section' => $section,
        ], static fn ($v) => is_string($v) && trim($v) !== '');

        return $fields;
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function extractDetailMeta(string $html, array $fields): array
    {
        $meta = [
            'source' => 'runts_ricerca_enti',
            'fetched_at' => date('c'),
            'repertory' => $fields['runts'] ?? '',
            'name' => $fields['name'] ?? '',
            'fiscal_code' => $fields['fiscal_code'] ?? '',
            'section' => $fields['section'] ?? '',
            'forma_giuridica' => $this->spanText($html, 'dnn_ctr448_View_spnFormaGiuridica'),
            'pec' => $fields['pec'] ?? '',
            'website' => $fields['website'] ?? '',
            'iscritto_il' => $this->spanText($html, 'dnn_ctr448_View_spnIscrittoIl'),
            'atto_costitutivo' => $this->spanText($html, 'dnn_ctr448_View_spnAttoCostitutivo'),
            'ultimo_aggiornamento_statutario' => $this->spanText($html, 'dnn_ctr448_View_spnUltimoAggiornamentoStatutario'),
            'ente_non_commerciale' => $this->spanText($html, 'dnn_ctr448_View_spnEnteNonCommerciale'),
            'cinque_per_mille' => $this->spanText($html, 'dnn_ctr448_View_spnCinquePerMille'),
            'lavoratori' => $this->spanText($html, 'dnn_ctr448_View_spnLavoratoriSubordinati'),
            'volontari' => $this->spanText($html, 'dnn_ctr448_View_spnVolontari'),
            'soci_persone_fisiche' => $this->spanText($html, 'dnn_ctr448_View_spnSociPersonaFisica'),
            'sede' => [
                'province' => $fields['province'] ?? '',
                'city' => $fields['city'] ?? '',
                'address' => $fields['address'] ?? '',
                'house_number' => $fields['house_number'] ?? '',
                'postal_code' => $fields['postal_code'] ?? '',
            ],
            'people' => $this->extractPeople($html),
        ];

        return array_filter(
            $meta,
            static fn ($v) => $v !== '' && $v !== [] && $v !== null
        );
    }

    /**
     * @return list<array<string, string>>
     */
    private function extractPeople(string $html): array
    {
        $people = [];
        if (!preg_match_all(
            '/Persona\s+(\d+)<\/span>(.*?)(?=Persona\s+\d+<\/span>|$)/is',
            $html,
            $blocks,
            PREG_SET_ORDER
        )) {
            return [];
        }
        foreach ($blocks as $block) {
            $chunk = $block[2];
            $nome = $this->labeledValue($chunk, 'Nome');
            $cognome = $this->labeledValue($chunk, 'Cognome');
            if ($nome === '' && $cognome === '') {
                continue;
            }
            $people[] = [
                'index' => $block[1],
                'first_name' => $this->prettyName($nome),
                'last_name' => $this->prettyName($cognome),
                'is_legal_rep' => preg_match(
                    '/Rappresentante legale<\/span>\s*<span[^>]*>\s*S(?:i|&igrave;|ì)\s*<\/span>/iu',
                    $chunk
                ) === 1 ? '1' : '0',
                'role' => $this->labeledValue($chunk, 'Carica'),
                'appointed_on' => $this->labeledValue($chunk, 'Data nomina'),
            ];
        }
        return $people;
    }

    private function extractPresident(string $html): string
    {
        foreach ($this->extractPeople($html) as $person) {
            if (($person['is_legal_rep'] ?? '') === '1') {
                return trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
            }
            $role = mb_strtoupper((string) ($person['role'] ?? ''), 'UTF-8');
            if (str_contains($role, 'LEGALE RAPPRESENTANTE') || str_contains($role, 'PRESIDENTE')) {
                return trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
            }
        }
        return '';
    }

    private function labeledValue(string $html, string $label): string
    {
        $quoted = preg_quote($label, '/');
        if (preg_match(
            '/' . $quoted . '<\/span>\s*<span[^>]*class="[^"]*ente_testo-lg[^"]*"[^>]*>(.*?)<\/span>/is',
            $html,
            $m
        )) {
            return $this->cleanText($m[1]);
        }
        return '';
    }

    private function firstDetailButton(string $html): string
    {
        if (preg_match('/name="([^"]*btnDettaglio)"/i', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    /** @return array<string, string> */
    private function formPayload(string $html): array
    {
        $payload = [];
        if (preg_match_all(
            '/<input\b([^>]*)>/i',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $attrs = $m[1];
                if (!preg_match('/\bname="([^"]+)"/i', $attrs, $nameMatch)) {
                    continue;
                }
                $name = html_entity_decode($nameMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $type = 'text';
                if (preg_match('/\btype="([^"]+)"/i', $attrs, $typeMatch)) {
                    $type = strtolower($typeMatch[1]);
                }
                if (in_array($type, ['submit', 'button', 'image', 'file'], true)) {
                    continue;
                }
                $value = '';
                if (preg_match('/\bvalue="([^"]*)"/i', $attrs, $valueMatch)) {
                    $value = html_entity_decode($valueMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                $payload[$name] = $value;
            }
        }
        return $payload;
    }

    private function spanText(string $html, string $id): string
    {
        $quoted = preg_quote($id, '/');
        if (preg_match('/id="' . $quoted . '"[^>]*>(.*?)<\/(?:span|div)>/is', $html, $m)) {
            return $this->cleanText($m[1]);
        }
        return '';
    }

    private function cleanText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function prettyName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            return '';
        }
        $titled = mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        return preg_replace_callback(
            '/\b(Aps|Odv|Ets|Onlus|Coop|Asd|Ssd|Pec)\b/u',
            static fn (array $m): string => mb_strtoupper($m[1], 'UTF-8'),
            $titled
        ) ?? $titled;
    }

    private function normalizeWebsite(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . ltrim($raw, '/');
        }
        $parts = parse_url($raw);
        if (!is_array($parts) || empty($parts['host'])) {
            return rtrim($raw, '/');
        }
        $host = strtolower((string) $parts['host']);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        return rtrim($scheme . '://' . $host . $path . $query, '/');
    }

    /**
     * @return array{status:int,body:string,headers:string,effective_url:string}
     */
    private function http(string $method, string $url, ?array $fields, string $cookieFile, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'headers' => '', 'effective_url' => ''];
        }
        $ch = curl_init($url);
        $headers = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => max(15, $timeout),
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
            curl_setopt($ch, CURLOPT_REFERER, self::SEARCH_URL);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'headers' => $headers,
            'effective_url' => $effective,
        ];
    }
}
