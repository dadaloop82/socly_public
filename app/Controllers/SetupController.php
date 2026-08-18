<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\AssociationWebsiteScrapeService;
use Socly\Services\AuthService;
use Socly\Services\MailService;
use Socly\Services\RuntsLookupService;
use Socly\Services\SetupService;
use Socly\Setup\SetupCatalogue;

final class SetupController extends BaseController
{
    public function __construct(
        View $view,
        private readonly SetupService $setup,
        private readonly AuthService $auth,
        private readonly AssociationWebsiteScrapeService $scrape,
        private readonly MailService $mail,
        private readonly RuntsLookupService $runts
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        // Resuming configuration from the guest home clears the dismissed flag.
        unset($_SESSION['setup_dismissed']);

        if ((string) $request->input('intro', '') === '1') {
            unset($_SESSION['setup_greeted'], $_SESSION['setup_show_thanks'], $_SESSION['setup_progress_keys']);
            redirect('/setup');
        }

        $missing = $this->setup->missingSteps();
        if ($missing === [] && empty($_SESSION['setup_show_thanks'])) {
            redirect($this->afterSetupPath());
        }

        $hasProgress = $this->setup->hasProgress();
        $isIncremental = $this->setup->isIncrementalSetup();
        $catalogue = SetupCatalogue::all();
        $totalCatalogue = count($catalogue);
        $planKeys = $this->progressPlanKeys($missing, $catalogue);

        $greeted = !empty($_SESSION['setup_greeted']);
        if (!$greeted) {
            $this->render('setup/wizard', [
                'title' => __('setup.title'),
                'mode' => 'greeting',
                'greeting' => $this->setup->greetingPeriod(),
                'missingCount' => count($missing),
                'totalSteps' => max(1, count($planKeys)),
                'stepIndex' => 0,
                'hasProgress' => $hasProgress,
                'isIncremental' => $isIncremental,
            ], 'layouts/setup');
            return;
        }

        if (!empty($_SESSION['setup_show_thanks'])) {
            $requestedKey = trim((string) $request->input('step_key', ''));
            // “Indietro” from thanks must reopen the name step (clear the interstitial).
            if ($requestedKey !== '' && SetupCatalogue::findByKey($requestedKey) !== null) {
                unset($_SESSION['setup_show_thanks']);
            } else {
                $branding = app()->branding();
                $assocName = trim((string) ($branding['name'] ?? ''));
                if ($assocName === '') {
                    $assocName = 'SOCLY';
                }
                $namePos = array_search('association.name', $planKeys, true);
                $this->render('setup/wizard', [
                    'title' => __('setup.title'),
                    'mode' => 'thanks',
                    'assocName' => $assocName,
                    'assocLegal' => trim((string) ($branding['legal_name'] ?? '')),
                    'missingCount' => count($missing),
                    'totalSteps' => max(1, count($planKeys)),
                    'stepIndex' => $namePos === false ? 0 : (int) $namePos,
                    'hasProgress' => true,
                    'isIncremental' => $isIncremental,
                    'backHref' => url('/setup?step_key=association.name'),
                ], 'layouts/setup');
                return;
            }
        }

        if ($missing === []) {
            redirect($this->afterSetupPath());
        }

        $requestedKey = trim((string) $request->input('step_key', ''));
        $step = null;

        if ($requestedKey !== '') {
            $candidate = SetupCatalogue::findByKey($requestedKey);
            if ($candidate !== null) {
                $step = $candidate;
            }
        }

        if ($step === null) {
            $legacyIndex = max(0, (int) $request->input('step', 0));
            if ($legacyIndex >= count($missing)) {
                $legacyIndex = 0;
            }
            $step = $missing[$legacyIndex];
        }

        $stepIndex = $this->progressStepIndex((string) ($step['key'] ?? ''), $planKeys, $catalogue);
        $branding = app()->branding();
        $stepKey = (string) ($step['key'] ?? '');
        // Drop stale flashes that belong to another setup step.
        if (
            !empty($_SESSION['_flash']['setup_error_step'])
            && (string) $_SESSION['_flash']['setup_error_step'] !== $stepKey
        ) {
            unset($_SESSION['_flash']['errors'], $_SESSION['_flash']['setup_error_step']);
        }

        $this->render('setup/wizard', [
            'title' => __('setup.title'),
            'mode' => 'step',
            'step' => $step,
            'value' => $this->withDraftInput($step, $this->setup->currentValue($step)),
            'stepIndex' => $stepIndex,
            'totalSteps' => max(1, count($planKeys)),
            'isLast' => $this->isLastMissingOrBeyond($step, $missing),
            'hasProgress' => $hasProgress,
            'isIncremental' => $isIncremental,
            'assocName' => assoc_capitalize_name((string) ($branding['name'] ?? '')),
            'assocLegal' => trim((string) ($branding['legal_name'] ?? '')),
            'backHref' => $this->backHrefForStep($step, $planKeys),
        ], 'layouts/setup');
    }

    /**
     * Stable list of step keys the user still needs for this setup run.
     * Frozen on greet so “Passo X di Y” does not reset to 1 after every save.
     *
     * @param list<array<string, mixed>> $missing
     * @param list<array<string, mixed>> $catalogue
     * @return list<string>
     */
    private function progressPlanKeys(array $missing, array $catalogue): array
    {
        $missingKeys = array_values(array_filter(array_map(
            static fn (array $step): string => (string) ($step['key'] ?? ''),
            $missing
        )));

        $stored = $_SESSION['setup_progress_keys'] ?? null;
        if (is_array($stored) && $stored !== []) {
            $keys = [];
            foreach ($stored as $key) {
                $key = trim((string) $key);
                if ($key !== '' && SetupCatalogue::findByKey($key) !== null) {
                    $keys[] = $key;
                }
            }
            // Catalogue updates can add new required steps mid-run — append them.
            foreach ($missingKeys as $key) {
                if (!in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
            if ($keys !== []) {
                $keys = array_values(array_unique($keys));
                $_SESSION['setup_progress_keys'] = $keys;
                return $keys;
            }
        }

        $keys = $missingKeys;
        if ($keys === []) {
            $keys = array_values(array_filter(array_map(
                static fn (array $step): string => (string) ($step['key'] ?? ''),
                $catalogue
            )));
        }
        $_SESSION['setup_progress_keys'] = $keys;
        return $keys;
    }

    /**
     * @param list<string> $planKeys
     * @param list<array<string, mixed>> $catalogue
     */
    private function progressStepIndex(string $stepKey, array $planKeys, array $catalogue): int
    {
        $pos = array_search($stepKey, $planKeys, true);
        if ($pos !== false) {
            return (int) $pos;
        }
        // Fallback: catalogue order relative to the plan window.
        return $this->catalogueStepIndex(['key' => $stepKey], $catalogue);
    }

    /**
     * 0-based position of a step in the full catalogue.
     *
     * @param array<string, mixed> $step
     * @param list<array<string, mixed>> $catalogue
     */
    private function catalogueStepIndex(array $step, array $catalogue): int
    {
        $keys = array_column($catalogue, 'key');
        $pos = array_search((string) ($step['key'] ?? ''), $keys, true);
        return $pos === false ? 0 : (int) $pos;
    }

    /**
     * @param array<string, mixed> $step
     * @param list<array<string, mixed>> $missing
     */
    private function isLastMissingOrBeyond(array $step, array $missing): bool
    {
        if ($missing === []) {
            return true;
        }
        $last = $missing[count($missing) - 1] ?? null;
        return is_array($last) && ($last['key'] ?? '') === ($step['key'] ?? '');
    }

    /**
     * @param array<string, mixed> $step
     * @param list<string> $planKeys
     */
    private function backHrefForStep(array $step, array $planKeys): ?string
    {
        $pos = array_search((string) ($step['key'] ?? ''), $planKeys, true);
        if ($pos === false) {
            return url('/setup?intro=1');
        }
        if ((int) $pos === 0) {
            return url('/setup?intro=1');
        }
        $prevKey = (string) $planKeys[(int) $pos - 1];
        return url('/setup?step_key=' . rawurlencode($prevKey));
    }

    public function greet(Request $request): void
    {
        $_SESSION['setup_greeted'] = true;
        $missing = $this->setup->missingSteps();
        $_SESSION['setup_progress_keys'] = array_values(array_filter(array_map(
            static fn (array $step): string => (string) ($step['key'] ?? ''),
            $missing
        )));
        redirect($this->firstMissingHref());
    }

    public function thanks(Request $request): void
    {
        unset($_SESSION['setup_show_thanks']);
        $remaining = $this->setup->missingSteps();
        if ($remaining === []) {
            unset(
                $_SESSION['setup_greeted'],
                $_SESSION['setup_progress_keys'],
                $_SESSION['_setup_draft'],
                $_SESSION['_old']
            );
            $this->flash('success', __('setup.complete'));
            redirect($this->afterSetupPath());
        }
        redirect($this->hrefForStepKey((string) ($remaining[0]['key'] ?? '')));
    }

    public function discard(Request $request): void
    {
        // Logout first: audit_logs.user_id must still reference a living user.
        $wasAuthenticated = (bool) auth_user();
        if ($wasAuthenticated) {
            $this->auth->logout($request->ip());
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
        }

        if ($this->setup->isIncrementalSetup()) {
            // Explicit “discard everything” from incomplete setup: wipe association config.
            $this->setup->resetAssociationConfiguration();
        } else {
            $this->setup->discardProgress();
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset(
            $_SESSION['setup_greeted'],
            $_SESSION['setup_show_thanks'],
            $_SESSION['setup_progress_keys'],
            $_SESSION['_setup_draft'],
            $_SESSION['_old']
        );
        $_SESSION['setup_dismissed'] = true;
        redirect('/login');
    }

    public function exitSetup(Request $request): void
    {
        // Keep drafts so returning to Configura can restore temporary inputs.
        unset($_SESSION['setup_greeted'], $_SESSION['setup_show_thanks']);
        $_SESSION['setup_dismissed'] = true;
        if (auth_user()) {
            $this->auth->logout($request->ip());
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['setup_dismissed'] = true;
        }
        redirect('/login');
    }

    public function save(Request $request): void
    {
        $stepKey = trim((string) $request->input('step_key', ''));
        $missing = $this->setup->missingSteps();
        $stepIndex = max(0, (int) $request->input('step_index', 0));
        $step = null;

        if ($stepKey !== '') {
            foreach ($missing as $i => $candidate) {
                if (($candidate['key'] ?? '') === $stepKey) {
                    $step = $candidate;
                    $stepIndex = $i;
                    break;
                }
            }

            // Already completed (revisited via Indietro / scrape) — still persist edits.
            if ($step === null) {
                $fromCatalogue = SetupCatalogue::findByKey($stepKey);
                if ($fromCatalogue === null) {
                    redirect('/setup');
                }
                if ($this->setup->stepIsConfigured($fromCatalogue)) {
                    $this->storeDraft($fromCatalogue, $request);
                    $result = $this->setup->saveStep($fromCatalogue, $this->stepInput($request));
                    if (!$result['ok']) {
                        $this->flash('errors', $result['errors'] ?? [__('validation.required')]);
                        $this->flash('setup_error_step', (string) ($fromCatalogue['key'] ?? ''));
                        redirect('/setup?step_key=' . rawurlencode((string) ($fromCatalogue['key'] ?? '')));
                    }
                    if (($fromCatalogue['type'] ?? '') === 'admin_account' && !empty($result['admin_user_id'])) {
                        $this->auth->loginById((int) $result['admin_user_id'], $request->ip());
                    }
                    $this->clearDraft((string) ($fromCatalogue['key'] ?? ''));
                    $this->afterStepSaved(
                        $request,
                        $fromCatalogue,
                        $this->catalogueStepIndex($fromCatalogue, SetupCatalogue::all())
                    );
                }
                redirect($this->firstMissingHref());
            }
        } elseif (!isset($missing[$stepIndex])) {
            redirect('/setup');
        } else {
            $step = $missing[$stepIndex];
        }

        // Always keep temporary inputs so a failed/interrupted step can be restored.
        $this->storeDraft($step, $request);

        $result = $this->setup->saveStep($step, $this->stepInput($request));
        if (!$result['ok']) {
            $this->flash('errors', $result['errors'] ?? [__('validation.required')]);
            $this->flash('setup_error_step', (string) ($step['key'] ?? ''));
            $failedKey = trim((string) ($step['key'] ?? ''));
            if ($failedKey !== '') {
                redirect('/setup?step_key=' . rawurlencode($failedKey));
            }
            redirect('/setup?step=' . $stepIndex);
        }

        if (($step['type'] ?? '') === 'admin_account' && !empty($result['admin_user_id'])) {
            $this->auth->loginById((int) $result['admin_user_id'], $request->ip());
        }

        $this->clearDraft((string) ($step['key'] ?? ''));
        $this->afterStepSaved($request, $step, $stepIndex);
    }

    /**
     * @param array<string, mixed> $step
     */
    private function afterStepSaved(Request $request, array $step, int $stepIndex): void
    {
        if ((string) $request->input('setup_exit', '0') === '1') {
            unset($_SESSION['setup_greeted'], $_SESSION['setup_show_thanks']);
            if (auth_user()) {
                $this->auth->logout($request->ip());
            }
            redirect('/login');
        }

        if (($step['key'] ?? '') === 'association.name') {
            $_SESSION['setup_show_thanks'] = true;
            redirect('/setup');
        }

        $remaining = $this->setup->missingSteps();
        if ($remaining === []) {
            unset(
                $_SESSION['setup_greeted'],
                $_SESSION['setup_show_thanks'],
                $_SESSION['setup_progress_keys'],
                $_SESSION['_setup_draft'],
                $_SESSION['_old']
            );
            $this->flash('success', __('setup.complete'));
            redirect($this->afterSetupPath());
        }

        // Prefer the next incomplete step in catalogue order after the one just saved.
        $keys = array_column(SetupCatalogue::all(), 'key');
        $savedPos = array_search((string) ($step['key'] ?? ''), $keys, true);
        $nextKey = (string) ($remaining[0]['key'] ?? '');
        if ($savedPos !== false) {
            foreach ($remaining as $candidate) {
                $pos = array_search((string) ($candidate['key'] ?? ''), $keys, true);
                if ($pos !== false && $pos > $savedPos) {
                    $nextKey = (string) ($candidate['key'] ?? '');
                    break;
                }
            }
        }

        redirect($this->hrefForStepKey($nextKey));
    }

    private function firstMissingHref(): string
    {
        $remaining = $this->setup->missingSteps();
        if ($remaining === []) {
            return $this->afterSetupPath();
        }
        return $this->hrefForStepKey((string) ($remaining[0]['key'] ?? ''));
    }

    private function hrefForStepKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '/setup';
        }
        return '/setup?step_key=' . rawurlencode($key);
    }

    private function afterSetupPath(): string
    {
        return auth_user() ? '/dashboard' : '/login';
    }

    /**
     * @param array<string, mixed> $step
     */
    private function withDraftInput(array $step, mixed $value): mixed
    {
        $key = (string) ($step['key'] ?? '');
        $draft = $_SESSION['_setup_draft'][$key] ?? null;
        if (!is_array($draft) || $draft === []) {
            return $value;
        }

        $type = (string) ($step['type'] ?? '');
        if (in_array($type, ['colors', 'name_pair', 'field_group', 'address_block', 'president', 'admin_account', 'member_types', 'membership_periods', 'member_fields', 'platform_consents', 'smtp_config'], true)) {
            $base = is_array($value) ? $value : [];
            foreach ($draft as $field => $fieldValue) {
                if (in_array($field, ['_token', 'step_index', 'step_key', 'setup_exit', 'logo_file'], true)) {
                    continue;
                }
                if (in_array($field, ['news_opt_in', 'usage_stats_opt_in', 'showcase_consent'], true)) {
                    $base[$field] = !empty($fieldValue);
                    continue;
                }
                $base[$field] = $fieldValue;
            }
            return $base;
        }

        if ($type === 'component_select') {
            $base = is_array($value) ? $value : ['components' => []];
            $selected = $draft['components'] ?? [];
            if (!is_array($selected)) {
                $selected = [];
            }
            $selectedMap = array_fill_keys(array_map('strval', $selected), true);
            $items = [];
            foreach ($base['components'] ?? [] as $component) {
                if (!is_array($component)) {
                    continue;
                }
                $key = (string) ($component['key'] ?? '');
                $component['enabled'] = $key !== '' && isset($selectedMap[$key]);
                $items[] = $component;
            }
            $base['components'] = $items;
            return $base;
        }

        if ($type === 'people_list') {
            return is_array($draft['people'] ?? null) ? $draft['people'] : $value;
        }

        if ($type === 'checkbox') {
            return !empty($draft['value']);
        }

        if (array_key_exists('value', $draft)) {
            return $draft['value'];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $step
     */
    private function storeDraft(array $step, Request $request): void
    {
        $key = (string) ($step['key'] ?? '');
        if ($key === '') {
            return;
        }
        $input = $request->all();
        unset($input['_token'], $input['step_index'], $input['step_key'], $input['setup_exit']);
        $_SESSION['_setup_draft'][$key] = $input;
        $this->rememberOld($input);
    }

    private function clearDraft(string $stepKey): void
    {
        if ($stepKey !== '' && isset($_SESSION['_setup_draft'][$stepKey])) {
            unset($_SESSION['_setup_draft'][$stepKey]);
        }
        $this->clearOld();
    }

    public function uploadLogo(Request $request): void
    {
        if ($this->setup->isComplete() && !$this->setup->isAdmin()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $result = $this->setup->replaceSetupLogo($request->file('logo'));
        if (empty($result['ok'])) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => (string) ($result['error'] ?? __('validation.photo')),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'logo_url' => $result['logo_url'] ?? null,
            'primary' => $result['primary'] ?? '',
            'accent' => $result['accent'] ?? '',
            'palettes' => $result['palettes'] ?? [],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function discoverMail(Request $request): void
    {
        if ($this->setup->isComplete() && !$this->setup->isAdmin()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
            return;
        }

        @set_time_limit(90);
        $result = $this->mail->discover($request->all());
        if (empty($result['ok'])) {
            http_response_code(422);
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    public function autosaveFields(Request $request): void
    {
        if ($this->setup->isComplete() && !$this->setup->isAdmin()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => __('setup.fields_autosave_failed')], JSON_UNESCAPED_UNICODE);
            return;
        }

        $result = $this->setup->autosaveMemberFields($request->all());
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(!empty($result['ok']) ? 200 : 422);
        echo json_encode([
            'ok' => !empty($result['ok']),
            'message' => !empty($result['ok']) ? __('setup.fields_autosaved') : __('setup.fields_autosave_failed'),
            'errors' => $result['errors'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
    }

    public function testMail(Request $request): void
    {
        if ($this->setup->isComplete() && !$this->setup->isAdmin()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
            return;
        }

        @set_time_limit(90);
        $input = $request->all();
        $testTo = trim((string) ($input['test_to'] ?? ''));
        if ($testTo === '') {
            $testTo = trim((string) ($input['from_address'] ?? ''));
        }
        $input['test_to'] = $testTo;

        $save = $this->mail->saveSimple($input, false);
        if (empty($save['ok'])) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => (string) (($save['errors']['test'] ?? null)
                    ?: ($save['errors']['from_address'] ?? null)
                    ?: ($save['errors']['password'] ?? null)
                    ?: ($save['errors']['host'] ?? null)
                    ?: __('mail.test_failed')),
                'errors' => $save['errors'] ?? [],
                'needs_manual' => !empty($save['needs_manual']),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $test = $this->mail->sendTest($testTo);
        if (empty($test['ok'])) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => (string) ($test['error'] ?? __('mail.test_failed')),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $cfg = $this->mail->config();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'message' => __('mail.test_ok'),
            'host' => $cfg['host'],
            'port' => $cfg['port'],
            'encryption' => $cfg['encryption'],
            'username' => $cfg['username'],
            'last_test_ok' => true,
        ], JSON_UNESCAPED_UNICODE);
    }

    public function scrape(Request $request): void
    {
        if ($this->setup->isComplete() && !$this->setup->isAdmin()) {
            http_response_code(403);
            header('Content-Type: application/x-ndjson; charset=utf-8');
            echo json_encode(['type' => 'error', 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE) . "\n";
            return;
        }

        @set_time_limit(70);
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-cache, no-store');
        header('X-Accel-Buffering: no');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        $website = trim((string) $request->input('website', ''));
        $name = trim((string) ($request->input('name', '') ?: app()->branding()['name'] ?? ''));
        $locked = $this->setup->runtsLockedKeys();

        $emit = static function (array $event) use ($locked): void {
            if (($event['type'] ?? '') === 'found' && isset($locked[(string) ($event['key'] ?? '')])) {
                return;
            }
            echo json_encode($event, JSON_UNESCAPED_UNICODE) . "\n";
            flush();
        };

        $result = $this->scrape->scrape($website, $name, $emit);
        if (empty($result['ok'])) {
            try {
                app('logger')->anomaly('setup.scrape_failed', [
                    'website' => $website,
                    'error' => (string) ($result['error'] ?? 'unknown'),
                    'elapsed_ms' => $result['elapsed_ms'] ?? null,
                ]);
            } catch (\Throwable) {
            }
            // error already emitted by service when possible
            return;
        }

        $emit(['type' => 'progress', 'phase' => 'apply']);

        $canonical = $this->scrape->normalizeUrl((string) ($result['canonical_url'] ?? $result['source_url'] ?? $website))
            ?? trim($website);
        $found = is_array($result['found'] ?? null) ? $result['found'] : [];
        foreach (array_keys($this->setup->runtsLockedKeys()) as $lockedKey) {
            unset($found[$lockedKey]);
            if (isset($result['found']) && is_array($result['found'])) {
                unset($result['found'][$lockedKey]);
            }
        }
        $applied = [];
        try {
            $applied = $this->setup->applyScrapedHints($found, $canonical, true);
        } catch (\Throwable $e) {
            try {
                app('logger')->anomaly('setup.scrape_apply_failed', [
                    'website' => $website,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
            }
        }
        $result['website'] = $canonical;
        $result['applied'] = $applied;
        $result['applied_count'] = count($applied);
        $result['labels'] = is_array($result['labels'] ?? null) ? $result['labels'] : [];
        if ($canonical !== '') {
            $result['labels']['website'] = $this->scrape->labelForKey('website');
            if (!isset($result['found']['website'])) {
                $result['found']['website'] = $canonical;
            }
            if (!isset($found['website'])) {
                $emit([
                    'type' => 'found',
                    'key' => 'website',
                    'label' => $result['labels']['website'],
                    'value' => $canonical,
                ]);
            }
        }
        try {
            $branding = app(\Socly\Services\BrandingService::class);
            if ($branding->logoUrl() !== null) {
                $result['logo_url'] = $branding->logoUrl();
            }
            $primary = strtoupper(trim((string) app(\Socly\Services\SettingsService::class)->get('branding.primary', '')));
            $accent = strtoupper(trim((string) app(\Socly\Services\SettingsService::class)->get('branding.accent', '')));
            $palettes = $branding->paletteSuggestions();
            $result['primary'] = $primary !== '' ? $primary : null;
            $result['accent'] = $accent !== '' ? $accent : null;
            // Keep NDJSON compact: at most the first few palette suggestions.
            $result['palettes'] = array_slice($palettes, 0, 3);
            if ($primary !== '' && empty($result['found']['theme_primary'])) {
                $result['found']['theme_primary'] = $primary;
                $result['labels']['theme_primary'] = $this->scrape->labelForKey('theme_primary');
            }
            if ($accent !== '' && empty($result['found']['theme_accent'])) {
                $result['found']['theme_accent'] = $accent;
                $result['labels']['theme_accent'] = $this->scrape->labelForKey('theme_accent');
            }
        } catch (\Throwable) {
            $result['palettes'] = [];
            $result['primary'] = null;
            $result['accent'] = null;
        }

        $emit([
            'type' => 'done',
            'ok' => true,
            'website' => $result['website'] ?? $canonical,
            'found' => is_array($result['found'] ?? null) ? $result['found'] : [],
            'labels' => is_array($result['labels'] ?? null) ? $result['labels'] : [],
            'applied' => $applied,
            'applied_count' => count($applied),
            'elapsed_ms' => $result['elapsed_ms'] ?? null,
            'pages_fetched' => $result['pages_fetched'] ?? null,
            'logo_url' => $result['logo_url'] ?? null,
            'primary' => $result['primary'] ?? null,
            'accent' => $result['accent'] ?? null,
            'palettes' => $result['palettes'] ?? [],
            'source_url' => $result['source_url'] ?? null,
            'canonical_url' => $result['canonical_url'] ?? $canonical,
        ]);
    }

    public function lookupRunts(Request $request): void
    {
        if ($this->setup->isComplete() && !$this->setup->isAdmin()) {
            http_response_code(403);
            header('Content-Type: application/x-ndjson; charset=utf-8');
            echo json_encode(['type' => 'error', 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE) . "\n";
            return;
        }

        @set_time_limit(125);
        @ignore_user_abort(true);
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-cache, no-store');
        header('X-Accel-Buffering: no');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        $emit = static function (array $event): void {
            echo json_encode($event, JSON_UNESCAPED_UNICODE) . "\n";
            flush();
        };

        $number = (string) $request->input('runts', '');
        $result = $this->runts->lookup($number, $emit);
        if (empty($result['ok'])) {
            $emit([
                'type' => 'error',
                'error' => (string) ($result['error'] ?? __('setup.runts_fail')),
                'elapsed_ms' => $result['elapsed_ms'] ?? null,
            ]);
            return;
        }

        $fields = is_array($result['fields'] ?? null) ? $result['fields'] : [];
        $applied = [];
        try {
            $emit(['type' => 'progress', 'phase' => 'apply', 'percent' => 98]);
            $applied = $this->setup->applyRuntsHints($fields);
        } catch (\Throwable $e) {
            $emit([
                'type' => 'error',
                'error' => __('setup.runts_fail'),
            ]);
            return;
        }

        $emit([
            'type' => 'done',
            'ok' => true,
            'cancelled' => !empty($result['cancelled']),
            'warning' => (string) ($result['warning'] ?? ''),
            'fields' => $fields,
            'applied' => $applied,
            'elapsed_ms' => $result['elapsed_ms'] ?? null,
            'message' => !empty($result['cancelled'])
                ? (string) ($result['warning'] ?? '')
                : __('setup.runts_ok', [
                    'name' => assoc_display_name(
                        (string) ($fields['name'] ?? ''),
                        (string) ($fields['legal_name'] ?? '')
                    ) ?: (string) ($fields['name'] ?? ''),
                ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function stepInput(Request $request): array
    {
        $input = $request->all();
        $logo = $request->file('logo');
        if ($logo !== null) {
            $input['logo_file'] = $logo;
        }
        $input['_client_ip'] = $request->ip();
        return $input;
    }
}
