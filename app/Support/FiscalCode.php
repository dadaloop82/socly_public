<?php

declare(strict_types=1);

namespace Socly\Support;

final class FiscalCode
{
    private const MONTHS = ['A', 'B', 'C', 'D', 'E', 'H', 'L', 'M', 'P', 'R', 'S', 'T'];

    private const ODD = [
        '0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9, '5' => 13, '6' => 15, '7' => 17, '8' => 19, '9' => 21,
        'A' => 1, 'B' => 0, 'C' => 5, 'D' => 7, 'E' => 9, 'F' => 13, 'G' => 15, 'H' => 17, 'I' => 19, 'J' => 21,
        'K' => 2, 'L' => 4, 'M' => 18, 'N' => 20, 'O' => 11, 'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14,
        'U' => 16, 'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24, 'Z' => 23,
    ];

    private const EVEN = [
        '0' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9,
        'A' => 0, 'B' => 1, 'C' => 2, 'D' => 3, 'E' => 4, 'F' => 5, 'G' => 6, 'H' => 7, 'I' => 8, 'J' => 9,
        'K' => 10, 'L' => 11, 'M' => 12, 'N' => 13, 'O' => 14, 'P' => 15, 'Q' => 16, 'R' => 17, 'S' => 18, 'T' => 19,
        'U' => 20, 'V' => 21, 'W' => 22, 'X' => 23, 'Y' => 24, 'Z' => 25,
    ];

    private const CHECK = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public static function compute(string $firstName, string $lastName, string $birthDate, string $gender, string $belfiore): ?string
    {
        $firstName = self::normalize($firstName);
        $lastName = self::normalize($lastName);
        $gender = strtoupper(trim($gender));
        $belfiore = strtoupper(trim($belfiore));
        if ($firstName === '' || $lastName === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
            return null;
        }
        if (!in_array($gender, ['M', 'F'], true) || !preg_match('/^[A-Z]\d{3}$/', $belfiore)) {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $birthDate);
        if (!$dt) {
            return null;
        }

        $partial = self::surnameCode($lastName)
            . self::nameCode($firstName)
            . $dt->format('y')
            . self::MONTHS[((int) $dt->format('n')) - 1]
            . self::dayGenderCode((int) $dt->format('j'), $gender)
            . $belfiore;

        return $partial . self::checkChar($partial);
    }

    private static function normalize(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = strtoupper(preg_replace('/[^A-Za-z]/', '', $value) ?? '');
        return $value;
    }

    private static function consonants(string $value): string
    {
        return preg_replace('/[AEIOU]/', '', $value) ?? '';
    }

    private static function vowels(string $value): string
    {
        return preg_replace('/[^AEIOU]/', '', $value) ?? '';
    }

    private static function surnameCode(string $surname): string
    {
        $code = self::consonants($surname) . self::vowels($surname) . 'XXX';
        return substr($code, 0, 3);
    }

    private static function nameCode(string $name): string
    {
        $cons = self::consonants($name);
        if (strlen($cons) >= 4) {
            $code = $cons[0] . $cons[2] . $cons[3];
        } else {
            $code = $cons . self::vowels($name) . 'XXX';
            $code = substr($code, 0, 3);
        }
        return $code;
    }

    private static function dayGenderCode(int $day, string $gender): string
    {
        if ($gender === 'F') {
            $day += 40;
        }
        return str_pad((string) $day, 2, '0', STR_PAD_LEFT);
    }

    private static function checkChar(string $partial): string
    {
        $sum = 0;
        $len = strlen($partial);
        for ($i = 0; $i < $len; $i++) {
            $ch = $partial[$i];
            $sum += ($i % 2 === 0) ? (self::ODD[$ch] ?? 0) : (self::EVEN[$ch] ?? 0);
        }
        return self::CHECK[$sum % 26];
    }

    public static function normalizeCode(string $fiscalCode): string
    {
        return strtoupper(preg_replace('/\s+/', '', $fiscalCode) ?? '');
    }

    /**
     * True when CF surname/name blocks match the given names (best-effort).
     */
    public static function matchesPersonName(string $fiscalCode, string $firstName, string $lastName): bool
    {
        $cf = self::normalizeCode($fiscalCode);
        if (strlen($cf) !== 16) {
            return false;
        }
        $first = self::normalize($firstName);
        $last = self::normalize($lastName);
        if ($first === '' || $last === '') {
            return false;
        }

        return substr($cf, 0, 3) === self::surnameCode($last)
            && substr($cf, 3, 3) === self::nameCode($first);
    }

    /**
     * Birth date inferred from CF, or null if undecodable.
     * Uses century heuristic: years within [now-120, now] prefer 1900/2000.
     */
    public static function birthDateFromCode(string $fiscalCode): ?\DateTimeImmutable
    {
        $cf = self::normalizeCode($fiscalCode);
        if (strlen($cf) !== 16) {
            return null;
        }
        $yy = (int) substr($cf, 6, 2);
        $monthChar = $cf[8];
        $dayRaw = (int) substr($cf, 9, 2);
        $month = array_search($monthChar, self::MONTHS, true);
        if ($month === false) {
            return null;
        }
        $month = (int) $month + 1;
        if ($dayRaw >= 41) {
            $dayRaw -= 40;
        }
        if ($dayRaw < 1 || $dayRaw > 31) {
            return null;
        }

        $nowYear = (int) date('Y');
        $candidates = [1900 + $yy, 2000 + $yy];
        $best = null;
        foreach ($candidates as $year) {
            if ($year < $nowYear - 120 || $year > $nowYear) {
                continue;
            }
            $dt = \DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-' . $dayRaw);
            if ($dt instanceof \DateTimeImmutable && $dt->format('Y-n-j') === $year . '-' . $month . '-' . $dayRaw) {
                $best = $dt;
            }
        }

        return $best;
    }

    public static function isAtLeastAge(string $fiscalCode, int $age = 18, ?\DateTimeInterface $asOf = null): ?bool
    {
        $birth = self::birthDateFromCode($fiscalCode);
        if ($birth === null) {
            return null;
        }
        $asOfDt = $asOf instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($asOf)
            : new \DateTimeImmutable('today');
        $limit = $birth->modify('+' . max(0, $age) . ' years');

        return $limit <= $asOfDt;
    }
}
