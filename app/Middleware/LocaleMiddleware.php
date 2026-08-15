<?php

declare(strict_types=1);

namespace Socly\Middleware;

use Socly\Core\App;
use Socly\Core\Http\Request;
use Socly\Core\Translator;
use Socly\Services\SettingsService;
use Socly\Services\SetupService;
use Socly\Support\SetupSessionKeeper;

final class LocaleMiddleware
{
    private const ALLOWED = ['it', 'de', 'en'];

    public function __construct(
        private readonly Translator $translator,
        private readonly App $app,
        private readonly SetupService $setup
    ) {
    }

    public function handle(Request $request): bool
    {
        $requested = (string) ($request->input('lang')
            ?? $request->input('locale')
            ?? '');

        if (in_array($requested, self::ALLOWED, true)) {
            $_SESSION['locale'] = $requested;
            $locale = $requested;
        } else {
            $locale = $this->resolveStoredLocale();
        }

        $this->translator->setLocale($locale);

        // Keep setup drafts alive across login/setup while configuration is open.
        try {
            if ($this->app->isInstalled() && !$this->setup->isComplete()) {
                SetupSessionKeeper::keepAlive();
            }
        } catch (\Throwable) {
        }

        return true;
    }

    private function resolveStoredLocale(): string
    {
        $sessionLocale = (string) ($_SESSION['locale'] ?? '');
        if (in_array($sessionLocale, self::ALLOWED, true)) {
            return $sessionLocale;
        }

        $userLocale = (string) (auth_user()['locale'] ?? '');
        if (in_array($userLocale, self::ALLOWED, true)) {
            $_SESSION['locale'] = $userLocale;
            return $userLocale;
        }

        $appLocale = $this->resolveAppLocale();
        $_SESSION['locale'] = $appLocale;
        return $appLocale;
    }

    private function resolveAppLocale(): string
    {
        try {
            if ($this->app->isInstalled()) {
                $fromSettings = (string) app(SettingsService::class)->get('app.locale', '');
                if (in_array($fromSettings, self::ALLOWED, true)) {
                    return $fromSettings;
                }
            }
        } catch (\Throwable) {
        }

        $cfg = (string) $this->app->config('app.locale', 'it');
        return in_array($cfg, self::ALLOWED, true) ? $cfg : 'it';
    }
}
