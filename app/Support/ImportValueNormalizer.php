<?php

declare(strict_types=1);

namespace Socly\Support;

/**
 * Value normalization for imported CSV cells.
 *
 * Every method is pure and side-effect free so the importer can preview the
 * normalized value before writing anything to the database. Formats accepted:
 * dates (ISO, IT, DE, SQL timestamps, Excel serials), amounts (1.234,56 / 1234.56),
 * booleans (0/1, sì/no, true/false), gender, statuses and payment methods.
 */
final class ImportValueNormalizer
{
    /** Sentinel values used by the mapping form. */
    public const TARGET_IGNORE = '__ignore';

    public const TARGET_NEW_PREFIX = 'new:';

    public const TARGET_FIELD_PREFIX = 'f:';

    public const TARGET_SYSTEM_PREFIX = 'sys:';

    /**
     * Compact matching key: lowercase, accents removed, only [a-z0-9].
     * "Data di nascita" → "datadinascita", "OMAGGIO (un ingresso)" → "omaggiouningresso".
     */
    public static function matchKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
        ]);
        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }

    /** Collapse whitespace and strip surrounding quotes. */
    public static function text(string $value): string
    {
        $value = trim($value, " \t\n\r\0\x0B\"'");
        return preg_replace('/\s+/u', ' ', $value) ?? '';
    }

    public static function detectValueKind(string $value): string
    {
        $value = self::text($value);
        if ($value === '') {
            return 'empty';
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return 'email';
        }
        if (preg_match('/^[A-Za-z]{6}\d{2}[A-Za-z]\d{2}[A-Za-z]\d{3}[A-Za-z]$/', $value) === 1) {
            return 'fiscal_code';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $value) === 1) {
            return 'datetime';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            || preg_match('/^\d{1,2}[\.\/-]\d{1,2}[\.\/-]\d{2,4}$/', $value) === 1) {
            return 'date';
        }
        if (preg_match('/^[\p{L}]+$/u', $value) === 1 && mb_strlen($value) <= 12) {
            return 'word';
        }
        if (preg_match('/^[+\d][\d\s\.\/()-]{5,}$/', $value) === 1) {
            return 'phone';
        }
        if (preg_match('/^[-+]?[\d\.\s]*\d[.,]\d{1,2}$/', $value) === 1 || preg_match('/[€$£]/u', $value) === 1) {
            return 'amount';
        }
        if (preg_match('/^\d+$/', $value) === 1) {
            return 'number';
        }
        return 'text';
    }

    /**
     * Majority kind for a column (empty cells ignored).
     *
     * @param list<string> $values
     */
    public static function detectColumnKind(array $values): string
    {
        $counts = [];
        foreach ($values as $value) {
            $kind = self::detectValueKind($value);
            if ($kind === 'empty') {
                continue;
            }
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }
        if ($counts === []) {
            return 'empty';
        }
        arsort($counts);
        return (string) array_key_first($counts);
    }

    public static function toDate(?string $value): ?string
    {
        $value = self::text((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1) {
            return self::validDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[\.\/-](\d{1,2})[\.\/-](\d{2,4})$/', $value, $m) === 1) {
            $first = (int) $m[1];
            $second = (int) $m[2];
            $year = (int) $m[3];
            if ($year < 100) {
                $year += $year <= 40 ? 2000 : 1900;
            }
            // Day-first (EU) unless impossible (e.g. 12/25/1980 → month-first).
            if ($first <= 12 && $second > 12) {
                return self::validDate($year, $first, $second);
            }
            if ($first > 31 || $second > 31) {
                return null;
            }
            return self::validDate($year, $second, $first);
        }
        // Excel serial date exported as a bare number.
        if (preg_match('/^\d{5}$/', $value) === 1) {
            $serial = (int) $value;
            if ($serial >= 20000 && $serial <= 60000) {
                return gmdate('Y-m-d', (int) (($serial - 25569) * 86400));
            }
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    public static function toDateTime(?string $value): ?string
    {
        $raw = self::text((string) $value);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/', $raw, $m) === 1) {
            return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?? '00');
        }
        $date = self::toDate($raw);
        if ($date === null) {
            return null;
        }
        $timestamp = strtotime($raw);
        $time = $timestamp === false ? null : date('H:i:s', $timestamp);
        return $date . ' ' . ($time ?? '00:00:00');
    }

    public static function toAmount(?string $value): ?float
    {
        $raw = self::text((string) $value);
        if ($raw === '') {
            return null;
        }
        $raw = (string) preg_replace('/[^\d,.\-]/u', '', $raw);
        if ($raw === '' || $raw === '-') {
            return null;
        }
        $hasComma = str_contains($raw, ',');
        $hasDot = str_contains($raw, '.');
        if ($hasComma && $hasDot) {
            // The rightmost separator is the decimal one.
            if (strrpos($raw, ',') > strrpos($raw, '.')) {
                $raw = str_replace('.', '', $raw);
                $raw = str_replace(',', '.', $raw);
            } else {
                $raw = str_replace(',', '', $raw);
            }
        } elseif ($hasComma) {
            $decimals = strlen(substr($raw, strrpos($raw, ',') + 1));
            $raw = $decimals === 3 ? str_replace(',', '', $raw) : str_replace(',', '.', $raw);
        } elseif ($hasDot) {
            $decimals = strlen(substr($raw, strrpos($raw, '.') + 1));
            if ($decimals === 3) {
                $raw = str_replace('.', '', $raw);
            }
        }
        return is_numeric($raw) ? round((float) $raw, 2) : null;
    }

    public static function toBool(?string $value): ?bool
    {
        $key = self::matchKey((string) $value);
        if ($key === '') {
            return null;
        }
        if (in_array($key, ['1', 'si', 's', 'y', 'yes', 'true', 'ja', 'x', 'on', 'attivo', 'attiva'], true)) {
            return true;
        }
        if (in_array($key, ['0', 'no', 'n', 'false', 'nein', 'off', 'nonattivo', 'nonattiva'], true)) {
            return false;
        }
        return null;
    }

    public static function toGender(?string $value): ?string
    {
        $key = self::matchKey((string) $value);
        if ($key === '') {
            return null;
        }
        if (in_array($key, ['m', 'm1', 'u', 'uomo', 'maschio', 'male', 'mannlich', 'herr', 'mr'], true)) {
            return 'M';
        }
        if (in_array($key, ['f', 'f1', 'd', 'donna', 'femmina', 'female', 'weiblich', 'frau', 'ms'], true)) {
            return 'F';
        }
        if (in_array($key, ['x', 'altro', 'other', 'divers', 'nonbinario'], true)) {
            return 'X';
        }
        if (str_starts_with($key, 'm')) {
            return 'M';
        }
        return str_starts_with($key, 'f') ? 'F' : null;
    }

    public static function toStatus(?string $value): ?string
    {
        $key = self::matchKey((string) $value);
        return match (true) {
            $key === '' => null,
            str_contains($key, 'pending'), str_contains($key, 'attesa'), str_contains($key, 'pendente') => 'pending',
            str_contains($key, 'suspend'), str_contains($key, 'sospes') => 'suspended',
            str_contains($key, 'expired'), str_contains($key, 'scadut'), str_contains($key, 'abgelaufen') => 'expired',
            str_contains($key, 'cancel'), str_contains($key, 'annull'), str_contains($key, 'cancell'),
            str_contains($key, 'dismess') => 'cancelled',
            str_contains($key, 'active'), str_contains($key, 'attiv'), str_contains($key, 'aktiv') => 'active',
            default => null,
        };
    }

    public static function toPaymentMethod(?string $value): ?string
    {
        $key = self::matchKey((string) $value);
        return match (true) {
            $key === '' => null,
            str_contains($key, 'cash'), str_contains($key, 'contant'), str_contains($key, 'bargeld'),
            str_contains($key, 'cassa') => 'cash',
            str_contains($key, 'bank'), str_contains($key, 'bonific'), str_contains($key, 'sepa'),
            str_contains($key, 'uberweisung'), str_contains($key, 'transfer'),
            str_contains($key, 'pos'), str_contains($key, 'carta'), str_contains($key, 'card'),
            str_contains($key, 'karte'), str_contains($key, 'credit'), str_contains($key, 'paypal'),
            str_contains($key, 'satispay'), str_contains($key, 'assegno'), str_contains($key, 'check') => 'bank',
            str_contains($key, 'other'), str_contains($key, 'altro'), str_contains($key, 'sonstig') => 'other',
            default => null,
        };
    }

    public static function toEmail(?string $value): ?string
    {
        $raw = mb_strtolower(self::text((string) $value), 'UTF-8');
        if ($raw === '' || !str_contains($raw, '@')) {
            return null;
        }
        return $raw;
    }

    public static function toPhone(?string $value): ?string
    {
        $raw = self::text((string) $value);
        if ($raw === '') {
            return null;
        }
        $digits = preg_replace('/[^\d+]/', '', $raw) ?? '';
        return $digits === '' ? null : $digits;
    }

    public static function toFiscalCode(?string $value): string
    {
        $raw = mb_strtoupper(self::text((string) $value), 'UTF-8');
        return preg_replace('/[^A-Z0-9]/', '', $raw) ?? '';
    }

    /** Suggested human label for a "create new field" target built from a CSV header. */
    public static function labelFromHeader(string $header): string
    {
        $label = self::text($header);
        $label = (string) preg_replace('/^(id|nr|n)\s*[\.:]?\s+/i', '', $label);
        $label = (string) preg_replace('/\s*\((?:attual[ei]|current)\)\s*$/iu', '', $label);
        $label = trim($label);
        if ($label === '') {
            $label = self::text($header);
        }
        return mb_strlen($label) > 60 ? mb_substr($label, 0, 60) : $label;
    }

    private static function validDate(int $year, int $month, int $day): ?string
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
