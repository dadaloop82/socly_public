<?php

declare(strict_types=1);

namespace Socly\Support;

/**
 * Minimal, dependency-free projection for the Italy outline map used by the statistics page.
 *
 * The base outline (`public/assets/img/italy-outline.svg`) is generated from Natural Earth
 * (public domain) with a plain equirectangular projection, so the bubble positions stay aligned.
 */
final class ItalyMap
{
    public const LON_MIN = 6.627734;
    public const LON_MAX = 18.48584;
    public const LAT_MIN = 36.687842;
    public const LAT_MAX = 47.082129;

    /** @return array{x:float,y:float} Position in percent of the map box (0-100). */
    public static function project(float $lat, float $lon): array
    {
        $x = ($lon - self::LON_MIN) / (self::LON_MAX - self::LON_MIN) * 100;
        $y = (self::LAT_MAX - $lat) / (self::LAT_MAX - self::LAT_MIN) * 100;
        return [
            'x' => round(max(0.0, min(100.0, $x)), 2),
            'y' => round(max(0.0, min(100.0, $y)), 2),
        ];
    }

    /** Inline SVG markup of the Italy outline (empty string when the asset is missing). */
    public static function svg(): string
    {
        $path = base_path('public/assets/img/italy-outline.svg');
        if (!is_file($path)) {
            return '';
        }
        $svg = (string) file_get_contents($path);
        return str_contains($svg, '<svg') ? $svg : '';
    }
}
