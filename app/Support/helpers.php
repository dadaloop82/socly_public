<?php

declare(strict_types=1);

use Socly\Core\App;

if (!function_exists('app')) {
    function app(?string $abstract = null): mixed
    {
        $app = App::getInstance();
        return $abstract === null ? $app : $app->get($abstract);
    }
}

if (!function_exists('__')) {
    function __(string $key, array $replace = []): string
    {
        return app('translator')->get($key, $replace);
    }
}

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('view_partial')) {
    /** Render a view partial to string (no layout). */
    function view_partial(string $view, array $data = []): string
    {
        return app(\Socly\Core\View::class)->renderPartial($view, $data);
    }
}

if (!function_exists('base_url')) {
    function base_url(): string
    {
        $configured = rtrim((string) (app()->config('app.url') ?? ''), '/');

        // Isolated instances: trust APP_URL so links omit /public.
        if (
            defined('SOCLY_INSTANCE_PATH')
            && defined('SOCLY_CODE_PATH')
            && SOCLY_INSTANCE_PATH !== SOCLY_CODE_PATH
            && $configured !== ''
        ) {
            return $configured;
        }

        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') {
            return $configured;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';

        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = str_replace('\\', '/', dirname($scriptName));
        if ($dir === '/' || $dir === '.' || $dir === '\\') {
            $dir = '';
        }

        // Always prefer the live request host + subdirectory (fixes wrong APP_URL after local tests)
        return $scheme . '://' . $host . $dir;
    }
}

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        $base = base_url();
        $path = '/' . ltrim($path, '/');
        if ($path === '/') {
            return $base === '' ? '/' : $base . '/';
        }
        return $base . $path;
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        return url('/assets/' . ltrim($path, '/'));
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return app('csrf')->token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed
    {
        return $_SESSION['_old'][$key] ?? $default;
    }
}

if (!function_exists('old_input')) {
    /** @return array<string, mixed> */
    function old_input(): array
    {
        $old = $_SESSION['_old'] ?? [];
        return is_array($old) ? $old : [];
    }
}

if (!function_exists('flash')) {
    function flash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path): never
    {
        header('Location: ' . url($path));
        exit;
    }
}

/**
 * Full reassurance line for setup ETA (~15 seconds per step), with correct singular/plural.
 */
if (!function_exists('setup_eta_reassure_line')) {
    function setup_eta_reassure_line(int $steps): string
    {
        $seconds = max(0, $steps) * 15;
        if ($seconds < 45) {
            return __('setup.greeting_reassure_eta_under');
        }

        $minutes = (int) max(1, (int) ceil($seconds / 60));
        if ($minutes === 1) {
            return __('setup.greeting_reassure_eta_one');
        }

        return __('setup.greeting_reassure_eta_many', ['count' => (string) $minutes]);
    }
}

if (!function_exists('code_path')) {
    /** Shared application code root (may differ from instance data root). */
    function code_path(string $path = ''): string
    {
        $base = defined('SOCLY_CODE_PATH') ? (string) SOCLY_CODE_PATH : dirname(__DIR__, 2);
        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('base_path')) {
    /** Instance root: .env, storage, installed.lock (falls back to code root). */
    function base_path(string $path = ''): string
    {
        $base = defined('SOCLY_INSTANCE_PATH') ? (string) SOCLY_INSTANCE_PATH : code_path();
        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }
}

if (!function_exists('user_upload_period')) {
    /**
     * Year/month folder for user uploads (reference date or today).
     *
     * @return array{year:string,month:string}
     */
    function user_upload_period(?string $referenceDate = null): array
    {
        $ts = time();
        if ($referenceDate !== null && trim($referenceDate) !== '') {
            $parsed = strtotime(trim($referenceDate));
            if ($parsed !== false) {
                $ts = $parsed;
            }
        }
        return [
            'year' => date('Y', $ts),
            'month' => date('m', $ts),
        ];
    }
}

if (!function_exists('user_upload_paths')) {
    /**
     * Build storage paths under uploads/users/YYYY/MM/{category}/.
     *
     * @return array{dir:string,relative_dir:string,relative:string,absolute:string}
     */
    function user_upload_paths(string $category, ?string $referenceDate = null, string $filename = ''): array
    {
        $category = trim($category, '/');
        $period = user_upload_period($referenceDate);
        $relativeDir = 'users/' . $period['year'] . '/' . $period['month'] . '/' . $category;
        $dir = storage_path('uploads/' . $relativeDir);
        ensure_directory($dir);
        $filename = ltrim($filename, '/');
        $relative = $filename !== '' ? $relativeDir . '/' . $filename : $relativeDir;
        $absolute = $filename !== '' ? $dir . '/' . $filename : $dir;
        return [
            'dir' => $dir,
            'relative_dir' => $relativeDir,
            'relative' => $relative,
            'absolute' => $absolute,
        ];
    }
}

if (!function_exists('user_upload_relative_path')) {
    /** Relative path stored in DB (under storage/uploads/, without uploads/ prefix). */
    function user_upload_relative_path(string $category, ?string $referenceDate, string $filename): string
    {
        return user_upload_paths($category, $referenceDate, $filename)['relative'];
    }
}

if (!function_exists('is_user_upload_relative_path')) {
    function is_user_upload_relative_path(string $relative): bool
    {
        $relative = ltrim($relative, '/');
        if (str_starts_with($relative, 'uploads/')) {
            $relative = substr($relative, strlen('uploads/'));
        }
        if (str_contains($relative, '..') || str_contains($relative, '\\')) {
            return false;
        }
        if (str_starts_with($relative, 'users/')) {
            return (bool) preg_match('#^users/\d{4}/\d{2}/[a-zA-Z0-9._/-]+$#', $relative);
        }
        if (str_starts_with($relative, 'members/') || str_starts_with($relative, 'enrollment/') || str_starts_with($relative, 'branding/')) {
            return (bool) preg_match('#^(members|enrollment|branding)/[a-zA-Z0-9._/-]+$#', $relative);
        }
        if (str_starts_with($relative, 'documents/')) {
            return (bool) preg_match('#^documents/[a-zA-Z0-9._-]+$#', $relative);
        }
        return false;
    }
}

if (!function_exists('locale_flag_url')) {
    function locale_flag_url(string $locale): string
    {
        $code = strtolower(substr(preg_replace('/[^a-z]/i', '', $locale) ?: 'it', 0, 2));
        if (!in_array($code, ['it', 'de', 'en'], true)) {
            $code = 'it';
        }
        // Local SVG assets (no external CDN) — English uses UK flag.
        return asset('flags/' . $code . '.svg');
    }
}

if (!function_exists('dial_flag_url')) {
    function dial_flag_url(string $iso): string
    {
        $code = strtolower(substr(preg_replace('/[^a-z]/i', '', $iso) ?: 'it', 0, 2));

        return 'https://flagcdn.com/w20/' . rawurlencode($code) . '.png';
    }
}

if (!function_exists('phone_dial_codes')) {
    /** @return list<array{iso:string,dial:string,name:string}> */
    function phone_dial_codes(): array
    {
        static $codes = null;
        if ($codes === null) {
            /** @var list<array{iso:string,dial:string,name:string}> $codes */
            $codes = require code_path('app/Support/phone_dial_codes.php');
        }

        return $codes;
    }
}

if (!function_exists('phone_dial_sort_keys')) {
    /** @return list<string> Dial codes longest-first for parsing. */
    function phone_dial_sort_keys(): array
    {
        static $keys = null;
        if ($keys === null) {
            $keys = array_values(array_unique(array_map(
                static fn (array $row): string => (string) $row['dial'],
                phone_dial_codes()
            )));
            usort($keys, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        }

        return $keys;
    }
}

if (!function_exists('parse_stored_phone')) {
    /**
     * @return array{dial:string,national:string}
     */
    function parse_stored_phone(?string $raw, string $defaultDial = '39'): array
    {
        $cleaned = trim((string) $raw);
        if ($cleaned === '') {
            return ['dial' => $defaultDial, 'national' => ''];
        }

        $digits = preg_replace('/\D+/', '', $cleaned) ?? '';
        $dial = $defaultDial;
        $national = $cleaned;

        if (str_starts_with($cleaned, '+')) {
            foreach (phone_dial_sort_keys() as $code) {
                if ($digits !== '' && str_starts_with($digits, $code) && strlen($digits) > strlen($code) + 3) {
                    $dial = $code;
                    $national = substr($digits, strlen($code));
                    break;
                }
            }
        } elseif (str_starts_with($digits, '00')) {
            $rest = substr($digits, 2);
            foreach (phone_dial_sort_keys() as $code) {
                if ($rest !== '' && str_starts_with($rest, $code) && strlen($rest) > strlen($code) + 3) {
                    $dial = $code;
                    $national = substr($rest, strlen($code));
                    break;
                }
            }
        } elseif ($defaultDial === '39' && str_starts_with($digits, '39') && strlen($digits) > 11) {
            $dial = '39';
            $national = substr($digits, 2);
        } else {
            $national = preg_replace('/\D+/', '', $cleaned) ?? '';
        }

        $national = trim(preg_replace('/\s+/', ' ', preg_replace('/\D/', ' ', (string) $national) ?? '') ?? '');

        return ['dial' => $dial, 'national' => $national];
    }
}

if (!function_exists('format_stored_phone')) {
    function format_stored_phone(string $dial, string $national): string
    {
        $dial = preg_replace('/\D+/', '', $dial) ?? '';
        $national = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\d\s]/', '', $national) ?? '') ?? '');
        if ($dial === '' || $national === '') {
            return '';
        }

        return '+' . $dial . ' ' . $national;
    }
}

if (!function_exists('normalize_phone_value')) {
    function normalize_phone_value(?string $raw, string $defaultDial = '39'): string
    {
        $parsed = parse_stored_phone($raw, $defaultDial);
        if ($parsed['national'] === '') {
            return '';
        }

        return format_stored_phone($parsed['dial'], $parsed['national']);
    }
}

if (!function_exists('is_valid_phone_value')) {
    function is_valid_phone_value(?string $raw, string $defaultDial = '39'): bool
    {
        $parsed = parse_stored_phone($raw, $defaultDial);
        if ($parsed['national'] === '') {
            return true;
        }

        $digits = preg_replace('/\D+/', '', $parsed['national']) ?? '';
        if ($digits === '' || strlen($digits) < 4 || strlen($digits) > 14) {
            return false;
        }

        if ($parsed['dial'] === '39') {
            if (str_starts_with($digits, '39') && strlen($digits) > 10) {
                $digits = substr($digits, 2);
            }

            return (bool) preg_match('/^(?:3\d{8,9}|0\d{5,10})$/', $digits);
        }

        return (bool) preg_match('/^\d{4,14}$/', $digits);
    }
}

if (!function_exists('validate_adult_birth_date')) {
    /** @return string|null Validation message key or null if valid/empty */
    function validate_adult_birth_date(?string $date): ?string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return 'validation.date';
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return 'validation.date';
        }
        $today = strtotime('today');
        if ($ts > $today) {
            return 'validation.birth_date_future';
        }
        $minAdult = strtotime('-18 years', $today);
        if ($ts > $minAdult) {
            return 'validation.birth_date_minor';
        }
        return null;
    }
}

if (!function_exists('socly_site_url')) {
    /** Base URL of socly.it for all app ↔ website API calls. */
    function socly_site_url(): string
    {
        // Use apex host: www.socly.it 301-redirects and breaks browser CORS for news/API.
        $url = trim((string) ($_ENV['SOCLY_SITE_URL'] ?? 'https://socly.it'));
        if ($url === '') {
            $url = 'https://socly.it';
        }
        return rtrim($url, '/');
    }
}

if (!function_exists('socly_site_api_url')) {
    function socly_site_api_url(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        return socly_site_url() . $path;
    }
}

if (!function_exists('socly_news_api_url')) {
    function socly_news_api_url(): string
    {
        $override = trim((string) ($_ENV['SOCLY_NEWS_API_URL'] ?? ''));
        if ($override !== '') {
            return $override;
        }
        return socly_site_api_url('/api/news.php');
    }
}

if (!function_exists('socly_platform_api_url')) {
    function socly_platform_api_url(): string
    {
        $override = trim((string) ($_ENV['SOCLY_PLATFORM_API_URL'] ?? ''));
        if ($override !== '') {
            return $override;
        }
        return socly_site_api_url('/api/platform.php');
    }
}

if (!function_exists('resolve_upload_absolute_path')) {
    /** Resolve a stored relative upload path to an absolute filesystem path. */
    function resolve_upload_absolute_path(string $relative): ?string
    {
        $relative = ltrim($relative, '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }
        if (str_starts_with($relative, 'uploads/')) {
            $full = storage_path($relative);
        } elseif (str_starts_with($relative, 'documents/')) {
            $full = storage_path($relative);
        } else {
            $full = storage_path('uploads/' . $relative);
        }
        return is_file($full) ? $full : null;
    }
}

if (!function_exists('is_temporary_instance')) {
    function is_temporary_instance(): bool
    {
        try {
            return (string) app(\Socly\Services\SettingsService::class)->get('app.temporary_instance', '0') === '1';
        } catch (\Throwable) {
            return defined('SOCLY_INSTANCE_PATH')
                && defined('SOCLY_CODE_PATH')
                && SOCLY_INSTANCE_PATH !== SOCLY_CODE_PATH;
        }
    }
}

if (!function_exists('ensure_directory')) {
    /** Create a directory if missing; never throws on permission warnings. */
    function ensure_directory(string $path, int $mode = 0775): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }
        $ok = @mkdir($path, $mode, true);
        return ($ok || is_dir($path)) && is_writable($path);
    }
}

if (!function_exists('credit_line')) {
    function credit_line(): string
    {
        return str_replace(
            ':heart',
            '<span class="heart" aria-hidden="true">❤</span>',
            e(__('auth.footer_tagline'))
        );
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return app()->config($key, $default);
    }
}

if (!function_exists('auth_user')) {
    function auth_user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }
}

if (!function_exists('can')) {
    function can(string $permission): bool
    {
        $user = auth_user();
        if (!$user) {
            return false;
        }
        if (!empty($user['is_system_admin'])) {
            return true;
        }
        $perms = $_SESSION['permissions'] ?? [];
        return in_array($permission, $perms, true);
    }
}

if (!function_exists('component_enabled')) {
    function component_enabled(string $key): bool
    {
        try {
            if (!app()->isInstalled()) {
                return true;
            }
            return app(\Socly\Services\ComponentService::class)->isEnabled($key);
        } catch (\Throwable) {
            return true;
        }
    }
}

if (!function_exists('upload_limit_bytes')) {
    function upload_limit_bytes(): int
    {
        try {
            if (app()->isInstalled()) {
                return app(\Socly\Services\DocumentService::class)->uploadLimitBytes();
            }
        } catch (\Throwable) {
        }
        return 25 * 1024 * 1024;
    }
}

if (!function_exists('upload_max_mb')) {
    function upload_max_mb(): int
    {
        return max(1, (int) ceil(upload_limit_bytes() / (1024 * 1024)));
    }
}

if (!function_exists('upload_post_too_large')) {
    function upload_post_too_large(): bool
    {
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length < 1) {
            return false;
        }
        return $length > upload_limit_bytes();
    }
}

if (!function_exists('require_component')) {
    function require_component(string $key): void
    {
        if (!component_enabled($key)) {
            redirect('/dashboard');
        }
    }
}

if (!function_exists('app_version')) {
    function app_version(): string
    {
        $configured = (string) config('app.version', '');
        if ($configured !== '') {
            return sanitize_app_version($configured);
        }
        $path = base_path('VERSION');
        if (is_file($path)) {
            return sanitize_app_version((string) file_get_contents($path));
        }
        return '0.0.0';
    }
}

if (!function_exists('sanitize_app_version')) {
    /** Keep only a clean semver; strip accidental git conflict markers. */
    function sanitize_app_version(string $raw): string
    {
        $raw = str_replace("\r", '', $raw);
        if (str_contains($raw, '<<<<<<') || str_contains($raw, '>>>>>>') || str_contains($raw, '======')) {
            if (preg_match_all('/\b(\d+\.\d+\.\d+)\b/', $raw, $matches) && $matches[1] !== []) {
                // Prefer the release series (1.1.x) over CI chore bumps (1.0.x) when both appear.
                $candidates = $matches[1];
                usort($candidates, static function (string $a, string $b): int {
                    $pa = array_map('intval', explode('.', $a));
                    $pb = array_map('intval', explode('.', $b));
                    return $pb <=> $pa;
                });
                return $candidates[0];
            }
        }
        $line = trim(explode("\n", $raw, 2)[0] ?? '');
        if (preg_match('/^\d+\.\d+\.\d+$/', $line) === 1) {
            return $line;
        }
        if (preg_match('/\b(\d+\.\d+\.\d+)\b/', $raw, $m) === 1) {
            return $m[1];
        }
        return '0.0.0';
    }
}

if (!function_exists('app_locale')) {
    function app_locale(): string
    {
        return (string) (auth_user()['locale'] ?? config('app.locale', 'it'));
    }
}

if (!function_exists('format_date')) {
    /**
     * Display a stored ISO date (Y-m-d or datetime) in the active locale.
     * Italian/English: dd/mm/yyyy · German: dd.mm.yyyy
     */
    function format_date(null|string $value, ?string $locale = null): string
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return '';
        }
        $datePart = substr($value, 0, 10);
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $datePart);
        if (!$dt instanceof \DateTimeImmutable) {
            try {
                $dt = new \DateTimeImmutable($value);
            } catch (\Throwable) {
                return $value;
            }
        }
        $locale = $locale ?? app_locale();
        return match ($locale) {
            'de' => $dt->format('d.m.Y'),
            default => $dt->format('d/m/Y'),
        };
    }
}

if (!function_exists('localized')) {
    function localized(mixed $value, ?string $locale = null): string
    {
        $locale = $locale ?? (auth_user()['locale'] ?? config('app.locale', 'it'));
        if (is_array($value)) {
            return (string) ($value[$locale] ?? $value['it'] ?? reset($value) ?: '');
        }
        if (is_string($value) && str_starts_with($value, '{')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return localized($decoded, $locale);
            }
        }
        return (string) $value;
    }
}

if (!function_exists('format_datetime')) {
    function format_datetime(null|string $value, ?string $locale = null): string
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return '';
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable) {
            return '';
        }
        $locale = $locale ?? app_locale();
        return $date->format($locale === 'de' ? 'd.m.Y H:i' : 'd/m/Y H:i');
    }
}

if (!function_exists('assoc_capitalize_name')) {
    /** First letter uppercase (UTF-8), rest unchanged. */
    function assoc_capitalize_name(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $first = mb_substr($name, 0, 1, 'UTF-8');
        $rest = mb_substr($name, 1, null, 'UTF-8');
        return mb_strtoupper($first, 'UTF-8') . $rest;
    }
}

if (!function_exists('sentence_case')) {
    /** First letter uppercase and the remaining text lowercase (UTF-8). */
    function sentence_case(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return '';
        }
        $value = mb_strtolower($value, 'UTF-8');
        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8')
            . mb_substr($value, 1, null, 'UTF-8');
    }
}

if (!function_exists('assoc_name_contains_legal')) {
    /**
     * True when the association name already embeds the legal-form code (or APS long form),
     * so we must not append / demand it again.
     */
    function assoc_name_contains_legal(string $name, string $legal): bool
    {
        $name = trim($name);
        $legal = strtoupper(trim($legal));
        if ($name === '' || $legal === '') {
            return false;
        }
        $upper = mb_strtoupper($name, 'UTF-8');
        $quoted = preg_quote($legal, '/');
        // Token match: avoid “APS APS” and false hits inside longer words.
        if (preg_match('/(?:^|[\s\-–—,.;:\/(])' . $quoted . '(?:$|[\s\-–—,.;:\/)])/u', $upper) === 1) {
            return true;
        }
        if ($legal === 'APS' && mb_stripos($name, 'Associazione di Promozione Sociale', 0, 'UTF-8') !== false) {
            return true;
        }
        return false;
    }
}

if (!function_exists('assoc_display_name')) {
    /**
     * Plain-text association name + legal suffix (skipped if already in the name).
     */
    function assoc_display_name(?string $name = null, ?string $legal = null): string
    {
        $branding = app()->branding();
        $name = assoc_capitalize_name((string) ($name ?? $branding['name'] ?? ''));
        $legal = strtoupper(trim((string) ($legal ?? $branding['legal_name'] ?? '')));
        if ($name === '' || strcasecmp($name, 'SOCLY') === 0) {
            return '';
        }
        if ($legal !== '' && !assoc_name_contains_legal($name, $legal)) {
            return trim($name . ' ' . $legal);
        }
        return $name;
    }
}

if (!function_exists('assoc_lockup_html')) {
    /**
     * Association display: name (normal) + legal name (smaller, faded).
     * Skips the legal suffix when it is already present in the name.
     */
    function assoc_lockup_html(array $opts = []): string
    {
        $branding = app()->branding();
        $name = assoc_capitalize_name((string) ($opts['name'] ?? $branding['name'] ?? ''));
        $legal = strtoupper(trim((string) ($opts['legal_name'] ?? $branding['legal_name'] ?? '')));
        if ($name === '' || strcasecmp($name, 'SOCLY') === 0) {
            return '';
        }
        $mod = trim((string) ($opts['class'] ?? ''));
        $class = 'assoc-lockup' . ($mod !== '' ? ' ' . $mod : '');
        $html = '<span class="' . e($class) . '">';
        $html .= '<span class="assoc-name" title="' . e($name) . '">' . e($name) . '</span>';
        if ($legal !== '' && !assoc_name_contains_legal($name, $legal)) {
            $html .= '<span class="assoc-legal">' . e($legal) . '</span>';
        }
        $html .= '</span>';
        return $html;
    }
}

if (!function_exists('socly_word_html')) {
    /** Standalone SOCLY wordmark as styled text (for footers / inline with other copy). */
    function socly_word_html(string $class = ''): string
    {
        $cls = 'socly-word' . ($class !== '' ? ' ' . $class : '');
        return '<span class="' . e($cls) . '">SOCLY</span>';
    }
}

if (!function_exists('with_socly_word')) {
    /**
     * Escape text and style every “SOCLY” / “Socly” like the product wordmark (color/weight/size).
     */
    function with_socly_word(string $text): string
    {
        $escaped = e($text);
        $out = preg_replace('/Socly/i', socly_word_html(), $escaped);
        return is_string($out) ? $out : $escaped;
    }
}

if (!function_exists('with_auth_asterisk')) {
    /**
     * Mark free-plan asterisks as muted (for login brand copy / license footnote).
     * Expects already-escaped HTML (e.g. from with_socly_word / e()).
     */
    function with_auth_asterisk(string $html): string
    {
        return str_replace('*', '<span class="auth-asterisk" aria-hidden="true">*</span>', $html);
    }
}

if (!function_exists('socly_mark_url')) {
    /** Full Socly lockup (icon + wordmark) from assets. */
    function socly_mark_url(string $tone = 'dark'): string
    {
        $file = $tone === 'light' ? 'socly-mark-light.png' : 'socly-mark.png';
        $path = base_path('public/assets/img/' . $file);
        $v = is_file($path) ? (string) filemtime($path) : (string) time();
        return asset('img/' . $file) . '?v=' . rawurlencode($v);
    }
}

if (!function_exists('assoc_logo_url')) {
    function assoc_logo_url(): ?string
    {
        if (!app()->isInstalled()) {
            return null;
        }
        try {
            $path = trim((string) app(\Socly\Services\SettingsService::class)->get('branding.logo', ''));
            if ($path === '' || str_contains($path, '..')) {
                return null;
            }
            $absolute = storage_path('uploads/' . ltrim($path, '/'));
            if (!is_file($absolute)) {
                return null;
            }
            return url('/branding/logo') . '?v=' . rawurlencode((string) filemtime($absolute));
        } catch (\Throwable) {
            return null;
        }
    }
}

if (!function_exists('assoc_logo_img')) {
    function assoc_logo_img(string $class = 'assoc-logo', string $alt = ''): string
    {
        $url = assoc_logo_url();
        if ($url === null) {
            return '';
        }
        if ($alt === '') {
            $branding = app()->branding();
            $alt = trim((string) ($branding['name'] ?? 'SOCLY'));
        }
        return '<img class="' . e($class) . '" src="' . e($url) . '" alt="' . e($alt) . '" decoding="async">';
    }
}

if (!function_exists('brand_normalize_hex')) {
    function brand_normalize_hex(?string $hex, string $fallback = '#000000'): string
    {
        $raw = strtoupper(trim((string) $hex));
        if (preg_match('/^#[0-9A-F]{6}$/', $raw) === 1) {
            return $raw;
        }
        if (preg_match('/^#[0-9A-F]{3}$/', $raw) === 1) {
            return '#' . $raw[1] . $raw[1] . $raw[2] . $raw[2] . $raw[3] . $raw[3];
        }
        $fallback = strtoupper(trim($fallback));
        return preg_match('/^#[0-9A-F]{6}$/', $fallback) === 1 ? $fallback : '#000000';
    }
}

if (!function_exists('brand_hex_to_rgb')) {
    /** @return array{0:int,1:int,2:int} */
    function brand_hex_to_rgb(string $hex): array
    {
        $hex = brand_normalize_hex($hex);
        return [
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2)),
        ];
    }
}

if (!function_exists('brand_rgb_to_hex')) {
    function brand_rgb_to_hex(int $r, int $g, int $b): string
    {
        return sprintf(
            '#%02X%02X%02X',
            max(0, min(255, $r)),
            max(0, min(255, $g)),
            max(0, min(255, $b))
        );
    }
}

if (!function_exists('brand_mix_hex')) {
    /** Mix $from toward $to by $amount (0..1). */
    function brand_mix_hex(string $from, string $to, float $amount): string
    {
        $amount = max(0.0, min(1.0, $amount));
        [$r1, $g1, $b1] = brand_hex_to_rgb($from);
        [$r2, $g2, $b2] = brand_hex_to_rgb($to);
        return brand_rgb_to_hex(
            (int) round($r1 + ($r2 - $r1) * $amount),
            (int) round($g1 + ($g2 - $g1) * $amount),
            (int) round($b1 + ($b2 - $b1) * $amount)
        );
    }
}

if (!function_exists('brand_relative_luminance')) {
    function brand_relative_luminance(string $hex): float
    {
        $channel = static function (int $value): float {
            $c = $value / 255.0;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = brand_hex_to_rgb($hex);
        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }
}

if (!function_exists('brand_contrast_ratio')) {
    function brand_contrast_ratio(string $a, string $b): float
    {
        $l1 = brand_relative_luminance($a);
        $l2 = brand_relative_luminance($b);
        $hi = max($l1, $l2);
        $lo = min($l1, $l2);
        return ($hi + 0.05) / ($lo + 0.05);
    }
}

if (!function_exists('brand_readable_hex')) {
    /**
     * Adjust $fg toward black/white until it meets WCAG contrast against $bg.
     */
    function brand_readable_hex(string $fg, string $bg = '#FFFFFF', float $minRatio = 4.5): string
    {
        $fg = brand_normalize_hex($fg);
        $bg = brand_normalize_hex($bg, '#FFFFFF');
        if (brand_contrast_ratio($fg, $bg) >= $minRatio) {
            return $fg;
        }
        $toward = brand_relative_luminance($bg) > 0.45 ? '#000000' : '#FFFFFF';
        $best = $fg;
        $bestRatio = brand_contrast_ratio($fg, $bg);
        for ($i = 1; $i <= 48; $i++) {
            $candidate = brand_mix_hex($fg, $toward, $i / 48);
            $ratio = brand_contrast_ratio($candidate, $bg);
            if ($ratio > $bestRatio) {
                $best = $candidate;
                $bestRatio = $ratio;
            }
            if ($ratio >= $minRatio) {
                return $candidate;
            }
        }
        return $best;
    }
}

if (!function_exists('brand_derived_css_vars')) {
    /**
     * Readable brand text colors for light and dark surfaces.
     *
     * @return array<string,string>
     */
    function brand_derived_css_vars(?string $primary = null, ?string $accent = null): array
    {
        $primary = brand_normalize_hex($primary ?? '#0D6E66', '#0D6E66');
        $accent = brand_normalize_hex($accent ?? '#B84A1B', '#B84A1B');
        $paper = '#FFFFFF';
        $deep = brand_mix_hex($primary, '#000000', 0.72);
        $accentInk = brand_readable_hex($accent, $paper, 4.5);
        $primaryInk = brand_readable_hex($primary, $paper, 4.5);
        $accentOnDark = brand_readable_hex($accent, $deep, 4.5);
        $accentMuted = brand_readable_hex(brand_mix_hex($accentInk, $paper, 0.28), $paper, 3.0);

        return [
            '--brand-primary-ink' => $primaryInk,
            '--brand-accent-ink' => $accentInk,
            '--brand-accent-muted' => $accentMuted,
            '--brand-accent-on-dark' => $accentOnDark,
        ];
    }
}

if (!function_exists('brand_root_style_decls')) {
    /** Inline :root declarations for primary/accent + readable ink variants. */
    function brand_root_style_decls(?string $primary = null, ?string $accent = null): string
    {
        $primary = brand_normalize_hex($primary ?? '#0D6E66', '#0D6E66');
        $accent = brand_normalize_hex($accent ?? '#B84A1B', '#B84A1B');
        $lines = [
            '--brand-primary: ' . $primary,
            '--brand-accent: ' . $accent,
            '--brand-primary-deep: color-mix(in srgb, var(--brand-primary) 72%, black)',
        ];
        foreach (brand_derived_css_vars($primary, $accent) as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        return implode(";\n      ", $lines) . ';';
    }
}

if (!function_exists('socly_icon_url')) {
    /** Socly icon-only mark from assets. */
    function socly_icon_url(): string
    {
        $path = base_path('public/assets/img/socly-icon.png');
        $v = is_file($path) ? (string) filemtime($path) : (string) time();
        return asset('img/socly-icon.png') . '?v=' . rawurlencode($v);
    }
}

if (!function_exists('socly_mark_img')) {
    /** Render the full Socly logo image (replaces icon + “Socly” text). */
    function socly_mark_img(string $class = 'socly-mark', string $alt = 'SOCLY', string $tone = 'dark'): string
    {
        return '<img class="' . e($class) . '" src="' . e(socly_mark_url($tone)) . '" alt="' . e($alt) . '" decoding="async">';
    }
}

if (!function_exists('socly_icon_img')) {
    /** Render the Socly icon image (favicon / compact only). */
    function socly_icon_img(string $class = 'socly-icon', string $alt = 'SOCLY'): string
    {
        return '<img class="' . e($class) . '" src="' . e(socly_icon_url()) . '" alt="' . e($alt) . '" decoding="async">';
    }
}

