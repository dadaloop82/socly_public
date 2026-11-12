<?php

declare(strict_types=1);

namespace Socly\Support;

/**
 * Plausibility guard for calendar dates typed by hand or imported.
 *
 * A year such as 0026 or 0206 is always an accident — a truncated year, a shifted digit or a
 * mis-detected date column — never real data: accepting it pollutes the ledger, the pager, the
 * period filters and the reports. The accepted range is deliberately generous (associations have
 * long histories and a few years of planning are legitimate), but it rejects the obvious typos so
 * the form (or the import dry run) can show them and ask for a correction.
 */
final class PlausibleDate
{
    /** Earliest accepted year: before this a date is a typo, not a record. */
    public const MIN_YEAR = 1900;

    /** Latest accepted year: a few years ahead, for planning and multi-year bookings. */
    public static function maxYear(): int
    {
        return (int) date('Y') + 5;
    }

    public static function isPlausibleYear(int $year): bool
    {
        return $year >= self::MIN_YEAR && $year <= self::maxYear();
    }

    /**
     * True when the ISO date (`YYYY-MM-DD`) carries an acceptable year. A value that is not an ISO
     * date is left to the format rule, so the caller never reports the wrong problem.
     */
    public static function isPlausible(?string $date): bool
    {
        $date = trim((string) $date);
        if (preg_match('/^(\d{4})-/', $date, $matches) !== 1) {
            return true;
        }

        return self::isPlausibleYear((int) $matches[1]);
    }
}
