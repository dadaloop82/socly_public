<?php

declare(strict_types=1);

namespace Socly\Services;

final class CurrencyService
{
    private const DEFAULT_CURRENCY = 'EUR';
    private const CACHE_TTL = 43200;

    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function code(): string
    {
        $code = strtoupper(trim((string) $this->settings->get('app.currency', self::DEFAULT_CURRENCY)));
        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : self::DEFAULT_CURRENCY;
    }

    public function display(): string
    {
        $custom = trim((string) $this->settings->get('app.currency_display', ''));
        if ($custom !== '') {
            return mb_substr($custom, 0, 8);
        }
        return match ($this->code()) {
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'CHF' => 'CHF',
            default => $this->code(),
        };
    }

    public function format(float $amount): string
    {
        return number_format($amount, 2, ',', '.') . ' ' . $this->display();
    }

    public function convertToEur(float $amount, ?string $from = null): ?float
    {
        $rate = $this->rateToEur($from);
        return $rate !== null ? round($amount * $rate, 2) : null;
    }

    public function rateToEur(?string $from = null): ?float
    {
        $from = strtoupper(trim($from ?? $this->code()));
        if ($from === 'EUR') {
            return 1.0;
        }
        if (preg_match('/^[A-Z]{3}$/', $from) !== 1) {
            return null;
        }

        $cacheKey = 'currency.rate.' . strtolower($from) . '.eur';
        $cached = json_decode((string) $this->settings->get($cacheKey, ''), true);
        if (
            is_array($cached)
            && (float) ($cached['rate'] ?? 0) > 0
            && (int) ($cached['fetched_at'] ?? 0) >= time() - self::CACHE_TTL
        ) {
            return (float) $cached['rate'];
        }

        $rate = $this->fetchRate($from);
        if ($rate !== null) {
            $this->settings->set($cacheKey, [
                'rate' => $rate,
                'fetched_at' => time(),
                'source' => 'frankfurter.app',
            ]);
            return $rate;
        }

        // An expired cached quote is safer than failing completely while offline.
        return is_array($cached) && (float) ($cached['rate'] ?? 0) > 0
            ? (float) $cached['rate']
            : null;
    }

    private function fetchRate(string $from): ?float
    {
        $url = 'https://api.frankfurter.app/latest?from=' . rawurlencode($from) . '&to=EUR';
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: SOCLY\r\n",
            ],
        ]);
        try {
            $raw = @file_get_contents($url, false, $context);
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            $payload = json_decode($raw, true);
            $rate = is_array($payload) ? (float) ($payload['rates']['EUR'] ?? 0) : 0.0;
            return $rate > 0 ? $rate : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
