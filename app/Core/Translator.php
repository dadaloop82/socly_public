<?php

declare(strict_types=1);

namespace Socly\Core;

final class Translator
{
    private string $locale = 'it';

    /** @var array<string, array<string, mixed>> */
    private array $catalogs = [];

    public function __construct(private readonly string $langPath)
    {
    }

    public function setLocale(string $locale): void
    {
        $this->locale = in_array($locale, ['it', 'de', 'en'], true) ? $locale : 'it';
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function get(string $key, array $replace = []): string
    {
        $value = $this->lookup($this->locale, $key);
        if ($value === null && $this->locale !== 'it') {
            $value = $this->lookup('it', $key);
        }
        $value ??= $key;
        foreach ($replace as $search => $replacement) {
            $value = str_replace(':' . $search, (string) $replacement, $value);
        }
        return $value;
    }

    private function lookup(string $locale, string $key): ?string
    {
        $catalog = $this->load($locale);
        $segments = explode('.', $key);
        $value = $catalog;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return is_string($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    private function load(string $locale): array
    {
        if (!isset($this->catalogs[$locale])) {
            $file = $this->langPath . '/' . $locale . '/messages.php';
            $this->catalogs[$locale] = is_file($file) ? (require $file) : [];
        }
        return $this->catalogs[$locale];
    }
}
