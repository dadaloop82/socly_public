<?php

declare(strict_types=1);

namespace Socly\Support;

/**
 * Canonical reusable field modules for every SOCLY form.
 *
 * Usage: field('phone', ['name' => 'fields[phone]', 'label' => '...', 'value' => $v, 'required' => true])
 * Aliases: cf → fiscal_code, tel → phone, birth → birth_date, lang → language.
 */
final class Fields
{
    /** @var array<string, string> */
    private const ALIASES = [
        'tel' => 'phone',
        'cf' => 'fiscal_code',
        'fiscal' => 'fiscal_code',
        'birth' => 'birth_date',
        'lang' => 'language',
        'pwd' => 'password',
        'pass' => 'password',
        'check' => 'checkbox',
        'bool' => 'checkbox',
        'choice' => 'radio_cards',
        'choices' => 'radio_cards',
        'cards' => 'radio_cards',
        'geo_address' => 'address',
        'geo_birth' => 'birth_place',
        'city_block' => 'address',
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return [
            'text', 'email', 'number', 'search', 'color', 'date', 'textarea',
            'select', 'checkbox', 'radio', 'radio_cards',
            'phone', 'password', 'file', 'hidden',
            'gender', 'language', 'fiscal_code', 'birth_date',
            'birth_place', 'address', 'photo', 'locale_cards', 'control',
        ];
    }

    public static function resolve(string $type): string
    {
        $key = strtolower(trim($type));
        $aliases = self::ALIASES + [
            'locale' => 'locale_cards',
            'locales' => 'locale_cards',
            'lang_cards' => 'locale_cards',
        ];
        return $aliases[$key] ?? $key;
    }

    /**
     * @param array<string, mixed> $props
     */
    public static function render(string $type, array $props = []): string
    {
        $resolved = self::resolve($type);
        $view = 'fields/' . $resolved;
        $path = (defined('SOCLY_CODE_PATH') ? SOCLY_CODE_PATH : dirname(__DIR__, 2))
            . '/resources/views/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($path)) {
            throw new \InvalidArgumentException("Unknown field module [{$type}] → [{$resolved}].");
        }

        $props['type'] = $resolved;
        $props = self::normalizeProps($resolved, $props);

        return view_partial($view, $props);
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    public static function normalizeProps(string $type, array $props): array
    {
        $props['name'] = (string) ($props['name'] ?? '');
        $props['id'] = (string) ($props['id'] ?? self::defaultId((string) $props['name']));
        $props['label'] = array_key_exists('label', $props) ? (string) $props['label'] : '';
        $props['label_class'] = trim((string) ($props['label_class'] ?? ''));
        $props['label_attrs'] = is_array($props['label_attrs'] ?? null) ? $props['label_attrs'] : [];
        $props['label_extra'] = (string) ($props['label_extra'] ?? '');
        $props['wrap_attrs'] = is_array($props['wrap_attrs'] ?? null) ? $props['wrap_attrs'] : [];
        $props['value'] = $props['value'] ?? '';
        if (is_bool($props['value']) || is_int($props['value']) || is_float($props['value'])) {
            $props['value'] = (string) (int) $props['value'];
        } elseif (!is_string($props['value']) && !is_array($props['value'])) {
            $props['value'] = (string) $props['value'];
        }
        $props['required'] = !empty($props['required']);
        $props['disabled'] = !empty($props['disabled']);
        $props['readonly'] = !empty($props['readonly']);
        $props['hint'] = isset($props['hint']) ? (string) $props['hint'] : '';
        $props['placeholder'] = isset($props['placeholder']) ? (string) $props['placeholder'] : '';
        $props['autocomplete'] = isset($props['autocomplete']) ? (string) $props['autocomplete'] : '';
        $props['icon'] = isset($props['icon']) ? (string) $props['icon'] : '';
        $props['wrapper_class'] = trim((string) ($props['wrapper_class'] ?? ''));
        $props['input_class'] = trim((string) ($props['input_class'] ?? ''));
        $props['context'] = (string) ($props['context'] ?? 'default'); // default|setup|member|filter|inline
        $props['attrs'] = is_array($props['attrs'] ?? null) ? $props['attrs'] : [];
        $props['options'] = is_array($props['options'] ?? null) ? $props['options'] : [];
        $props['data_field'] = isset($props['data_field']) ? (string) $props['data_field'] : '';

        return $props;
    }

    public static function defaultId(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'field-' . bin2hex(random_bytes(3));
        }
        $id = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name) ?: 'field';
        return 'field-' . trim($id, '-');
    }

    /**
     * Render HTML attributes from a map (bool true → bare attr, false/null → skip).
     *
     * @param array<string, mixed> $attrs
     */
    public static function attrs(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || $value === false || $value === null) {
                continue;
            }
            if ($value === true) {
                $parts[] = e($key);
                continue;
            }
            $parts[] = e($key) . '="' . e((string) $value) . '"';
        }
        return $parts === [] ? '' : ' ' . implode(' ', $parts);
    }

    /**
     * Standard wrapper classes by context.
     */
    public static function wrapperClass(string $context, string $extra = '', string $modifier = ''): string
    {
        $base = match ($context) {
            'setup' => 'sf-field setup-field',
            'member' => 'sf-field field-block',
            'filter' => 'sf-field sf-field--filter',
            'inline' => 'sf-field sf-field--inline',
            default => 'sf-field field-block',
        };
        return trim($base . ($modifier !== '' ? ' ' . $modifier : '') . ($extra !== '' ? ' ' . $extra : ''));
    }
}
