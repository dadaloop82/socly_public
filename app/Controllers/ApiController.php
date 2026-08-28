<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\GeoService;
use Socly\Services\SetupService;
use Socly\Support\Permission;

final class ApiController extends BaseController
{
    public function __construct(
        View $view,
        private readonly GeoService $geo,
        private readonly SetupService $setup
    ) {
        parent::__construct($view);
    }

    public function cities(Request $request): void
    {
        $query = (string) $request->input('q', '');
        if ((string) $request->input('resolve', '') === '1') {
            $this->json($this->geo->resolveComuneQuery(
                $query,
                (string) $request->input('foreign', '') === '1'
            ));
            return;
        }
        $this->json([
            'items' => $this->geo->searchComuni($query),
        ]);
    }

    public function addresses(Request $request): void
    {
        $query = (string) $request->input('q', '');
        if ((string) $request->input('resolve', '') === '1') {
            $this->json($this->geo->resolveAddressQuery(
                $query,
                (string) $request->input('city', ''),
                (string) $request->input('house_number', '')
            ));
            return;
        }
        $this->json([
            'items' => $this->geo->searchAddresses(
                $query,
                (string) $request->input('city', '')
            ),
        ]);
    }

    public function cap(Request $request): void
    {
        $cap = (string) $request->input('q', '');
        if ((string) $request->input('resolve', '') === '1') {
            $this->json($this->geo->resolveCapQuery(
                $cap,
                (string) $request->input('city', '')
            ));
            return;
        }
        $this->json([
            'items' => $this->geo->findComuniByCap($cap),
        ]);
    }

    public function provinces(Request $request): void
    {
        $query = (string) $request->input('q', '');
        if ((string) $request->input('resolve', '') === '1') {
            $this->json($this->geo->resolveProvinceQuery($query));
            return;
        }
        $this->json([
            'items' => $this->geo->searchProvinces($query),
        ]);
    }

    public function fiscalCode(Request $request): void
    {
        try {
            if ($this->setup->isComplete() && !can(Permission::MEMBERS_MANAGE)) {
                $this->json(['ok' => false, 'error' => 'forbidden'], 403);
                return;
            }
        } catch (\Throwable) {
            $this->json(['ok' => false, 'error' => 'forbidden'], 403);
            return;
        }

        $result = $this->geo->computeFiscalCode(
            (string) $request->input('first_name', ''),
            (string) $request->input('last_name', ''),
            (string) $request->input('birth_date', ''),
            (string) $request->input('gender', ''),
            (string) $request->input('birth_place', '')
        );
        $this->json($result, $result['ok'] ? 200 : 422);
    }

    public function translate(Request $request): void
    {
        $raw = json_decode((string) file_get_contents('php://input'), true);
        $data = is_array($raw) ? $raw : $request->all();
        $text = trim((string) ($data['text'] ?? ''));
        $target = strtolower(trim((string) ($data['target'] ?? '')));
        $source = strtolower(trim((string) ($data['source'] ?? 'it')));
        if ($text === '' || !in_array($target, ['de', 'en'], true) || $source !== 'it') {
            $this->json(['ok' => false, 'message' => __('settings.legal_translate_fail')], 422);
            return;
        }

        $chunks = preg_split('/\R{2,}/', $text) ?: [$text];
        $out = [];
        foreach ($chunks as $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk === '') {
                $out[] = '';
                continue;
            }
            $parts = $this->splitTranslateChunk($chunk, 450);
            $translatedParts = [];
            foreach ($parts as $part) {
                $line = $this->callMyMemory($part, $source, $target);
                if ($line === null) {
                    $this->json(['ok' => false, 'message' => __('settings.legal_translate_fail')], 502);
                    return;
                }
                $translatedParts[] = $line;
            }
            $out[] = implode('', $translatedParts);
        }

        $this->json(['ok' => true, 'text' => implode("\n\n", $out)]);
    }

    /** @return list<string> */
    private function splitTranslateChunk(string $text, int $maxLen): array
    {
        if (mb_strlen($text) <= $maxLen) {
            return [$text];
        }
        $parts = [];
        $remaining = $text;
        while (mb_strlen($remaining) > $maxLen) {
            $slice = mb_substr($remaining, 0, $maxLen);
            $break = mb_strrpos($slice, ' ');
            if ($break === false || $break < (int) ($maxLen * 0.5)) {
                $break = $maxLen;
            }
            $parts[] = mb_substr($remaining, 0, $break);
            $remaining = ltrim(mb_substr($remaining, $break));
        }
        if ($remaining !== '') {
            $parts[] = $remaining;
        }

        return $parts;
    }

    private function callMyMemory(string $text, string $source, string $target): ?string
    {
        $url = 'https://api.mymemory.translated.net/get?' . http_build_query([
            'q' => $text,
            'langpair' => $source . '|' . $target,
            'de' => 'info@socly.it',
        ]);
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 20,
                'header' => "User-Agent: Socly/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return null;
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return null;
        }
        $translated = trim((string) ($json['responseData']['translatedText'] ?? ''));
        if ($translated === '' || strtoupper($translated) === 'INVALID TARGET LANGUAGE') {
            return null;
        }

        return $translated;
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
