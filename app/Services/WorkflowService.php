<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */


declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Core\Plugin\PluginManager;
use Socly\Core\Plugin\WorkflowEventProviderInterface;
use Socly\Core\Validator;

final class WorkflowService
{
    private static bool $dispatching = false;

    /** Bump when add-on discovery logic changes, so the persistent cache is rebuilt. */
    private const ADDON_EVENTS_CACHE_VERSION = 5;

    /** @var array<string, WorkflowEventProviderInterface>|null */
    private ?array $pluginProviders = null;

    /** @var array<string,string>|null */
    private ?array $auditMap = null;

    /** @var array<string, array<string, string>>|null */
    private ?array $eventGroupsCache = null;

    /** @var array{groups:array<string,array<string,string>>,audit:array<string,string>}|null */
    private ?array $addonEventsCache = null;

    public function __construct(
        private readonly Database $db,
        private readonly Validator $validator,
        private readonly EmailTemplateService $templates,
        private readonly MailService $mail,
        private readonly AuditService $audit
    ) {
    }

    /**
     * Core events available to Workflow. Labels are lang keys; the event key is stored in
     * workflow_rules.event_key (max 80 chars).
     *
     * @return array<string, array<string, string>>
     */
    public static function coreEventGroups(): array
    {
        return [
            'workflow.group_users' => [
                'user.created' => 'workflow.event_user_created',
                'user.updated' => 'workflow.event_user_updated',
                'user.deleted' => 'workflow.event_user_deleted',
            ],
            'workflow.group_members' => [
                'member.created' => 'workflow.event_member_created',
                'member.updated' => 'workflow.event_member_updated',
                'member.deleted' => 'workflow.event_member_deleted',
                'member.imported' => 'workflow.event_member_imported',
                'member.mass_renewed' => 'workflow.event_member_mass_renewed',
            ],
            'workflow.group_enrollment' => [
                'member.enrollment_otp' => 'workflow.event_enrollment_otp',
                'member.enrollment_attested' => 'workflow.event_enrollment_attested',
            ],
            'workflow.group_payments' => [
                'member.payment_reminder' => 'workflow.event_payment_reminder',
                'member.payment_received' => 'workflow.event_payment_received',
                'payment.treasury_registered' => 'workflow.event_payment_treasury_registered',
                'payment.treasury_deleted' => 'workflow.event_payment_treasury_deleted',
                'payment.cashbook_acknowledged' => 'workflow.event_payment_cashbook_acknowledged',
            ],
            'workflow.group_documents' => [
                'document.created' => 'workflow.event_document_created',
                'document.updated' => 'workflow.event_document_updated',
                'document.deleted' => 'workflow.event_document_deleted',
            ],
            'workflow.group_minutes' => [
                'minutes.created' => 'workflow.event_minutes_created',
            ],
            'workflow.group_deadlines' => [
                'deadline.created' => 'workflow.event_deadline_created',
                'deadline.updated' => 'workflow.event_deadline_updated',
                'deadline.done' => 'workflow.event_deadline_done',
                'deadline.renewed' => 'workflow.event_deadline_renewed',
            ],
            'workflow.group_treasury' => [
                'treasury.created' => 'workflow.event_treasury_created',
                'treasury.updated' => 'workflow.event_treasury_updated',
                'treasury.deleted' => 'workflow.event_treasury_deleted',
                'treasury.approved' => 'workflow.event_treasury_approved',
                'treasury.recurring_generated' => 'workflow.event_treasury_recurring_generated',
                'treasury.recurring_created' => 'workflow.event_treasury_recurring_created',
                'treasury.recurring_deleted' => 'workflow.event_treasury_recurring_deleted',
            ],
            'workflow.group_budgets' => [
                'budget.created' => 'workflow.event_budget_created',
                'budget.updated' => 'workflow.event_budget_updated',
                'budget.approved' => 'workflow.event_budget_approved',
                'budget.deleted' => 'workflow.event_budget_deleted',
                'budget.reopened' => 'workflow.event_budget_reopened',
            ],
            'workflow.group_org' => [
                'person.created' => 'workflow.event_person_created',
                'person.updated' => 'workflow.event_person_updated',
                'person.deleted' => 'workflow.event_person_deleted',
                'org_role.created' => 'workflow.event_org_role_created',
                'org_role.deleted' => 'workflow.event_org_role_deleted',
            ],
        ];
    }

    /** @return array<string, string> */
    public static function coreEvents(): array
    {
        $flat = [];
        foreach (self::coreEventGroups() as $events) {
            foreach ($events as $key => $labelKey) {
                $flat[$key] = $labelKey;
            }
        }

        return $flat;
    }

    /**
     * Core groups plus the groups declared by enabled plugins/add-ons. Plugin labels are final
     * display strings; core labels are lang keys resolved by the panel.
     *
     * @return array<string, array<string, string>>
     */
    public function eventGroups(): array
    {
        if ($this->eventGroupsCache !== null) {
            return $this->eventGroupsCache;
        }
        $groups = self::coreEventGroups();
        foreach ($this->pluginProviders() as $plugin) {
            try {
                $pluginGroups = $plugin->workflowEventGroups();
            } catch (\Throwable) {
                continue;
            }
            foreach ($pluginGroups as $groupLabel => $events) {
                if (!is_string($groupLabel) || !is_array($events)) {
                    continue;
                }
                foreach ($events as $eventKey => $label) {
                    if (!is_string($eventKey) || !is_string($label) || !$this->validEventKey($eventKey)) {
                        continue;
                    }
                    if (!isset($groups[$groupLabel][$eventKey])) {
                        $groups[$groupLabel][$eventKey] = $label;
                    }
                }
            }
        }

        // Add-ons available on disk may not implement the provider interface yet (older packages):
        // discover their actions from the source as a fallback.
        $known = [];
        foreach ($groups as $events) {
            foreach ($events as $eventKey => $label) {
                $known[$eventKey] = $label;
            }
        }
        foreach ($this->addonEvents()['groups'] as $groupLabel => $events) {
            foreach ($events as $eventKey => $label) {
                if (!is_string($eventKey) || !is_string($label) || isset($known[$eventKey])) {
                    continue;
                }
                $groups[$groupLabel][$eventKey] = $label;
                $known[$eventKey] = $label;
            }
        }

        return $this->eventGroupsCache = $groups;
    }

    /**
     * Display label for a workflow event. Core events store a lang key; add-on events store a
     * humanised key discovered from the add-on source ("Topic created"), so they are translated
     * here at render time (workflow.addon_events.<prefix>.<action>) and never in the cache.
     */
    public static function labelFor(string $eventKey, string $stored = ''): string
    {
        $stored = $stored !== '' ? $stored : $eventKey;
        try {
            if (str_starts_with($stored, 'workflow.')) {
                return (string) __($stored);
            }
            $langKey = 'workflow.addon_events.' . $eventKey;
            $translated = (string) __($langKey);
            if ($translated !== '' && $translated !== $langKey) {
                return $translated;
            }
        } catch (\Throwable) {
            // No translator available (CLI scripts): fall back to the stored label.
        }

        return $stored;
    }

    /** @return array<string, string> */
    public function events(): array
    {
        $flat = [];
        foreach ($this->eventGroups() as $events) {
            foreach ($events as $key => $labelKey) {
                $flat[$key] = $labelKey;
            }
        }

        return $flat;
    }

    /**
     * Audit action => workflow event, for mutations that already write the audit trail.
     * Explicit dispatches (user.created, enrollment OTP, payment reminder) are intentionally
     * absent so the same event cannot send two emails.
     *
     * @return array<string, string>
     */
    private static function coreAuditMap(): array
    {
        return [
            'member.created' => 'member.created',
            'member.updated' => 'member.updated',
            'member.deleted' => 'member.deleted',
            'members.imported' => 'member.imported',
            'member.mass_renewed' => 'member.mass_renewed',
            'member.enrollment_attested' => 'member.enrollment_attested',
            'payment.recorded' => 'member.payment_received',
            'payment.treasury_registered' => 'payment.treasury_registered',
            'payment.treasury_deleted' => 'payment.treasury_deleted',
            'payment.cashbook_acknowledged' => 'payment.cashbook_acknowledged',
            'document.created' => 'document.created',
            'document.updated' => 'document.updated',
            'document.deleted' => 'document.deleted',
            'minutes.created' => 'minutes.created',
            'deadline.created' => 'deadline.created',
            'deadline.updated' => 'deadline.updated',
            'deadline.done' => 'deadline.done',
            'deadline.renewed' => 'deadline.renewed',
            'treasury.created' => 'treasury.created',
            'treasury.updated' => 'treasury.updated',
            'treasury.deleted' => 'treasury.deleted',
            'treasury.approved' => 'treasury.approved',
            'treasury.recurring_generated' => 'treasury.recurring_generated',
            'treasury.recurring_created' => 'treasury.recurring_created',
            'treasury.recurring_deleted' => 'treasury.recurring_deleted',
            'budget.created' => 'budget.created',
            'budget.updated' => 'budget.updated',
            'budget.approved' => 'budget.approved',
            'budget.deleted' => 'budget.deleted',
            'budget.reopened' => 'budget.reopened',
            'person.created' => 'person.created',
            'person.updated' => 'person.updated',
            'person.deleted' => 'person.deleted',
            'org_role.created' => 'org_role.created',
            'org_role.deleted' => 'org_role.deleted',
            'user.updated' => 'user.updated',
            'user.deleted' => 'user.deleted',
        ];
    }

    /** @return array<string, string> */
    public function auditEventMap(): array
    {
        if ($this->auditMap !== null) {
            return $this->auditMap;
        }
        $map = self::coreAuditMap();
        $events = $this->events();
        foreach ($this->pluginProviders() as $plugin) {
            try {
                $pluginMap = $plugin->workflowAuditMap();
            } catch (\Throwable) {
                continue;
            }
            foreach ($pluginMap as $action => $eventKey) {
                if (is_string($action) && is_string($eventKey)
                    && isset($events[$eventKey]) && !isset($map[$action])) {
                    $map[$action] = $eventKey;
                }
            }
        }

        foreach ($this->addonEvents()['audit'] as $action => $eventKey) {
            if (isset($events[$eventKey]) && !isset($map[$action])) {
                $map[$action] = $eventKey;
            }
        }

        return $this->auditMap = $map;
    }

    /** @return array<string, WorkflowEventProviderInterface> */
    private function pluginProviders(): array
    {
        if ($this->pluginProviders !== null) {
            return $this->pluginProviders;
        }
        $out = [];
        try {
            if (!function_exists('app') || !app()->isInstalled()) {
                return $this->pluginProviders = [];
            }
            foreach (app(PluginManager::class)->enabled() as $id => $plugin) {
                if ($plugin instanceof WorkflowEventProviderInterface) {
                    $out[(string) $id] = $plugin;
                }
            }
        } catch (\Throwable) {
            $out = [];
        }

        return $this->pluginProviders = $out;
    }

    /**
     * Workflow event for an audit action. Core actions return without scanning add-ons, so the
     * audit bridge stays cheap for the vast majority of writes.
     */
    private function auditEventFor(string $action): string
    {
        $core = self::coreAuditMap();
        if (isset($core[$action])) {
            return $core[$action];
        }
        if ($this->isInternalAuditAction($action)) {
            return '';
        }

        return $this->addonEvents()['audit'][$action] ?? '';
    }

    private function isInternalAuditAction(string $action): bool
    {
        foreach (['workflow.', 'settings.', 'login', 'logout', 'password.', 'plugin.', 'addon.', 'update.', 'install.', 'demo.'] as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Events discovered from the add-ons that are installed and enabled in this instance
     * (packages in storage/addons, the addons/ drop-in, the addons-src/ sources): a package that
     * is only present on disk must not offer events. Older packages do not implement
     * WorkflowEventProviderInterface, so their audit actions and explicit workflow dispatches are
     * read from the PHP source and cached in storage/cache/workflow_addons.json.
     *
     * @return array{groups:array<string,array<string,string>>,audit:array<string,string>}
     */
    private function addonEvents(): array
    {
        if ($this->addonEventsCache !== null) {
            return $this->addonEventsCache;
        }
        $empty = ['groups' => [], 'audit' => []];
        $codePath = defined('SOCLY_CODE_PATH') ? SOCLY_CODE_PATH : dirname(__DIR__, 2);
        $installRoot = function_exists('storage_path') ? storage_path('addons') : $codePath . '/storage/addons';
        $candidates = $this->availableAddons((string) $installRoot, (string) $codePath);
        if ($candidates === []) {
            return $this->addonEventsCache = $empty;
        }

        $signature = $this->addonEventsSignature($candidates);
        $cached = $this->readAddonEventsCache($signature);
        if ($cached !== null) {
            return $this->addonEventsCache = $cached;
        }

        $coreEvents = [];
        foreach (self::coreEventGroups() as $events) {
            foreach ($events as $eventKey => $label) {
                $coreEvents[$eventKey] = true;
            }
        }
        $coreAudit = self::coreAuditMap();
        $groups = [];
        $audit = [];
        foreach ($candidates as $candidate) {
            $id = $candidate['id'];
            $name = $candidate['name'];
            $real = realpath($candidate['dir']);
            if ($real === false || !is_dir($real)) {
                continue;
            }
            $found = $this->scanAddonEvents($real);
            $labels = [];
            foreach (array_keys($found['audit']) as $action) {
                $action = (string) $action;
                if (!$this->validEventKey($action) || isset($coreEvents[$action]) || isset($coreAudit[$action])
                    || $this->isInternalAuditAction($action)) {
                    continue;
                }
                $labels[$action] = $this->addonEventLabel($action, $id);
                if (!isset($audit[$action])) {
                    $audit[$action] = $action;
                }
            }
            foreach (array_keys($found['dispatch']) as $eventKey) {
                $eventKey = (string) $eventKey;
                if (!$this->validEventKey($eventKey) || isset($coreEvents[$eventKey])
                    || $this->isInternalAuditAction($eventKey)) {
                    continue;
                }
                $labels[$eventKey] = $this->addonEventLabel($eventKey, $id);
            }
            if ($labels === []) {
                continue;
            }
            if (!isset($groups[$name])) {
                $groups[$name] = [];
            }
            foreach ($labels as $eventKey => $label) {
                if (!isset($groups[$name][$eventKey])) {
                    $groups[$name][$eventKey] = $label;
                }
            }
        }

        $data = ['groups' => $groups, 'audit' => $audit];
        $this->writeAddonEventsCache($signature, $data);

        return $this->addonEventsCache = $data;
    }

    /**
     * @param list<array{id:string,version:string,name:string,entry:string,dir:string,source:string}> $candidates
     */
    private function addonEventsSignature(array $candidates): string
    {
        $parts = [];
        foreach ($candidates as $candidate) {
            $dir = rtrim($candidate['dir'], '/');
            $fileParts = [];
            foreach ($this->addonPhpFiles($dir) as $file) {
                $relative = str_starts_with($file, $dir . '/') ? substr($file, strlen($dir) + 1) : $file;
                $fileParts[] = $relative . ':' . (string) @filesize($file) . ':' . (string) @filemtime($file);
            }
            $parts[] = implode('|', [
                $candidate['id'],
                $candidate['version'],
                $candidate['name'],
                $candidate['entry'],
                $candidate['source'],
                hash('sha256', implode("\n", $fileParts)),
            ]);
        }

        return hash('sha256', implode("\n", array_merge([(string) self::ADDON_EVENTS_CACHE_VERSION], $parts)));
    }

    /**
     * Every add-on we can see on disk: installed packages, the legacy addons/ drop-in and the
     * addons-src/ development sources. One candidate per add-on id, highest version wins.
     *
     * @return list<array{id:string,version:string,name:string,entry:string,dir:string,source:string}>
     */
    private function availableAddons(string $installRoot, string $codePath): array
    {
        $found = [];
        foreach ([$installRoot, rtrim($codePath, '/') . '/addons'] as $root) {
            if (!is_dir($root)) {
                continue;
            }
            foreach (glob(rtrim($root, '/') . '/*', GLOB_ONLYDIR) ?: [] as $idDir) {
                $id = basename($idDir);
                if (in_array($id, ['incoming', 'keys'], true) || !$this->validAddonSegment($id)) {
                    continue;
                }
                foreach (glob(rtrim($idDir, '/') . '/*', GLOB_ONLYDIR) ?: [] as $versionDir) {
                    $version = basename($versionDir);
                    if (!$this->validAddonSegment($version) || str_contains($version, '.bak-')) {
                        continue;
                    }
                    $manifest = $this->addonManifest($versionDir);
                    $entry = $this->addonEntry($manifest);
                    if (!is_file(rtrim($versionDir, '/') . '/' . $entry)) {
                        continue;
                    }
                    $name = trim((string) ($manifest['name'] ?? ''));
                    $this->addAddonCandidate(
                        $found,
                        $id,
                        $version,
                        $name !== '' ? $name : $id,
                        $entry,
                        $versionDir,
                        'package'
                    );
                }
            }
        }

        $sourceRoot = rtrim($codePath, '/') . '/addons-src';
        if (is_dir($sourceRoot)) {
            foreach (glob($sourceRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $manifest = $this->addonManifest($dir);
                $id = trim((string) ($manifest['id'] ?? ''));
                if ($id === '' || !$this->validAddonSegment($id)) {
                    $id = basename($dir);
                }
                $version = trim((string) ($manifest['version'] ?? ''));
                if ($version === '' || !$this->validAddonSegment($version)) {
                    $version = '0.0.0';
                }
                $entry = $this->addonEntry($manifest);
                if (!$this->validAddonSegment($id) || !is_file(rtrim($dir, '/') . '/' . $entry)) {
                    continue;
                }
                $name = trim((string) ($manifest['name'] ?? ''));
                $this->addAddonCandidate(
                    $found,
                    $id,
                    $version,
                    $name !== '' ? $name : $id,
                    $entry,
                    $dir,
                    'source'
                );
            }
        }

        $active = [];
        foreach ($found as $id => $candidate) {
            // Only add-ons that are actually installed and enabled can fire an event: a package
            // sitting in the folder (or a source tree) must never appear in Workflow.
            if ($this->addonIsActive((string) $id)) {
                $active[$id] = $candidate;
            }
        }

        return array_values($active);
    }

    /** True when the add-on is installed and enabled in this instance. */
    private function addonIsActive(string $id): bool
    {
        if (!function_exists('addon_active')) {
            return true; // helper unavailable (bare scripts): keep the old behaviour.
        }
        try {
            return addon_active($id);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,array{id:string,version:string,name:string,entry:string,dir:string,source:string}> $found
     */
    private function addAddonCandidate(
        array &$found,
        string $id,
        string $version,
        string $name,
        string $entry,
        string $dir,
        string $source
    ): void {
        if (!$this->validAddonSegment($id) || !$this->validAddonSegment($version)
            || !$this->validAddonEntry($entry) || !is_dir($dir)) {
            return;
        }
        $candidate = [
            'id' => $id,
            'version' => $version,
            'name' => $name !== '' ? $name : $id,
            'entry' => $entry,
            'dir' => rtrim($dir, '/'),
            'source' => $source,
        ];
        if (!isset($found[$id]) || version_compare($version, $found[$id]['version'], '>')) {
            $found[$id] = $candidate;
        }
    }

    /** @return array<string,mixed> */
    private function addonManifest(string $dir): array
    {
        $file = rtrim($dir, '/') . '/addon.json';
        if (!is_file($file) || (int) @filesize($file) > 131_072) {
            return [];
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string,mixed> $manifest */
    private function addonEntry(array $manifest): string
    {
        $entry = (string) ($manifest['entry'] ?? 'plugin.php');

        return $this->validAddonEntry($entry) ? $entry : 'plugin.php';
    }

    private function validAddonEntry(string $entry): bool
    {
        return preg_match('#^[A-Za-z0-9._/-]+\.php$#', $entry) === 1
            && !str_contains($entry, '..');
    }

    /**
     * Read audit actions and explicit dispatches from an add-on version directory. Actions passed
     * through a variable are recovered from quoted literals sharing the same namespace prefix.
     *
     * @return array{audit:array<string,true>,dispatch:array<string,true>}
     */
    private function scanAddonEvents(string $dir): array
    {
        $audit = [];
        $dispatch = [];
        $auditPattern = '/->audit\s*->\s*log\s*\(\s*[\'"]([A-Za-z0-9_.-]{3,80})[\'"]/';
        // Audit helpers (e.g. $this->store->audit('ade.invoice_matched', …)) carry real actions
        // as their first literal argument. Only calls named audit/log()/fire() are trusted:
        // scanning every literal would also pick up UI translation keys (treasury.manage, …).
        $auditArgPattern = '/(?:->|\b)(?:audit|log)\s*\(\s*[\'"]([A-Za-z0-9_.-]{3,80})[\'"]\s*[,)]/';
        $dispatchPattern = '/->workflow\s*->\s*dispatch\s*\(\s*[\'"]([A-Za-z0-9_.-]{3,80})[\'"]/';
        $servicePattern = '/WorkflowService::class\s*\)\s*->\s*dispatch\s*\(\s*[\'"]([A-Za-z0-9_.-]{3,80})[\'"]/';
        foreach ($this->addonPhpFiles($dir) as $file) {
            $source = @file_get_contents($file, false, null, 0, 2_000_000);
            if (!is_string($source) || $source === '') {
                continue;
            }
            foreach ([$auditPattern, $auditArgPattern] as $pattern) {
                if (preg_match_all($pattern, $source, $matches) > 0) {
                    foreach ($matches[1] as $action) {
                        $audit[(string) $action] = true;
                    }
                }
            }
            foreach ([$dispatchPattern, $servicePattern] as $pattern) {
                if (preg_match_all($pattern, $source, $matches) > 0) {
                    foreach ($matches[1] as $eventKey) {
                        $dispatch[(string) $eventKey] = true;
                    }
                }
            }
        }

        return ['audit' => $audit, 'dispatch' => $dispatch];
    }

    /** @return list<string> */
    private function addonPhpFiles(string $dir): array
    {
        $files = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                if (strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                if ($file->getSize() > 2_000_000) {
                    continue;
                }
                $files[] = $path;
                if (count($files) >= 500) {
                    break;
                }
            }
        } catch (\Throwable) {
            return [];
        }
        sort($files);

        return $files;
    }

    private function addonEventLabel(string $eventKey, string $addonId): string
    {
        $short = $eventKey;
        if ($addonId !== '' && str_starts_with($short, $addonId . '.')) {
            $short = substr($short, strlen($addonId) + 1);
        }
        $short = trim(str_replace(['.', '_', '-'], ' ', $short));

        return $short !== '' ? ucfirst($short) : $eventKey;
    }

    /**
     * @return array{groups:array<string,array<string,string>>,audit:array<string,string>}|null
     */
    private function readAddonEventsCache(string $signature): ?array
    {
        $path = $this->addonEventsCachePath();
        if ($path === null || !is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || (string) ($data['signature'] ?? '') !== $signature) {
            return null;
        }
        $groups = [];
        foreach ((array) ($data['groups'] ?? []) as $label => $events) {
            if (is_string($label) && is_array($events)) {
                $groups[$label] = $events;
            }
        }
        $audit = [];
        foreach ((array) ($data['audit'] ?? []) as $action => $eventKey) {
            if (is_string($action) && is_string($eventKey)) {
                $audit[$action] = $eventKey;
            }
        }

        return ['groups' => $groups, 'audit' => $audit];
    }

    /** @param array{groups:array<string,array<string,string>>,audit:array<string,string>} $data */
    private function writeAddonEventsCache(string $signature, array $data): void
    {
        $path = $this->addonEventsCachePath();
        if ($path === null) {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (function_exists('ensure_directory')) {
                ensure_directory($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
        }
        if (!is_dir($dir)) {
            return;
        }
        $json = json_encode([
            'signature' => $signature,
            'groups' => $data['groups'],
            'audit' => $data['audit'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            @file_put_contents($path, $json, LOCK_EX);
        }
    }

    private function addonEventsCachePath(): ?string
    {
        try {
            if (!function_exists('storage_path')) {
                return null;
            }

            return storage_path('cache/workflow_addons.json');
        } catch (\Throwable) {
            return null;
        }
    }

    private function validAddonSegment(string $segment): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.+-]{0,80}$/', $segment) === 1
            && !str_contains($segment, '..');
    }

    private function validEventKey(string $key): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_.-]{0,79}$/', $key) === 1;
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM workflow_rules ORDER BY event_key ASC, id ASC');
    }

    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM workflow_rules WHERE id = :id', ['id' => $id]);
    }

    /** @return array{ok:bool,errors?:array<string,string>,id?:int} */
    public function save(array $data): array
    {
        $id = (int) ($data['id'] ?? 0);
        $eventKey = (string) ($data['event_key'] ?? '');
        $templateSlug = trim((string) ($data['template_slug'] ?? ''));
        $knownEvent = isset($this->events()[$eventKey]);
        if (!$knownEvent && $id > 0) {
            // A rule created for an add-on that is now disabled must stay editable: the event key
            // is kept as-is and the rule simply stays dormant until the add-on is active again.
            $existing = $this->find($id);
            $knownEvent = is_array($existing) && (string) ($existing['event_key'] ?? '') === $eventKey;
        }
        if (!$knownEvent) {
            return ['ok' => false, 'errors' => ['event_key' => __('validation.required')]];
        }
        if (!$this->validator->validate($data, [
            'name' => 'required|string|max:160',
            'template_slug' => 'required|string|max:80',
        ])) {
            return ['ok' => false, 'errors' => $this->validator->firstErrors()];
        }
        if (!$this->templates->findBySlug($templateSlug)) {
            return ['ok' => false, 'errors' => ['template_slug' => __('workflow.template_missing')]];
        }
        $payload = [
            'name' => trim((string) $data['name']),
            'event_key' => $eventKey,
            'template_slug' => $templateSlug,
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'delay_minutes' => max(0, min(10080, (int) ($data['delay_minutes'] ?? 0))),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($id > 0) {
            $this->db->update('workflow_rules', $payload, 'id = :id', ['id' => $id]);
            return ['ok' => true, 'id' => $id];
        }
        $payload['created_at'] = date('Y-m-d H:i:s');
        return ['ok' => true, 'id' => $this->db->insert('workflow_rules', $payload)];
    }

    /** @return array{ok:bool,error?:string} */
    public function delete(int $id): array
    {
        if (!$this->find($id)) {
            return ['ok' => false, 'error' => __('validation.required')];
        }
        $this->db->query('DELETE FROM workflow_rules WHERE id = :id', ['id' => $id]);
        return ['ok' => true];
    }

    public function toggle(int $id): bool
    {
        $rule = $this->find($id);
        if (!$rule) {
            return false;
        }
        $enabled = empty($rule['enabled']) ? 1 : 0;
        $this->db->update('workflow_rules', [
            'enabled' => $enabled,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);
        return true;
    }

    /**
     * Entry point used by AuditService: forward a mapped audit action to the matching workflow.
     * Never throws: a workflow must not break the write that generated the audit row.
     */
    public function dispatchAudit(
        string $action,
        string $entityType,
        ?string $entityId,
        mixed $before = null,
        mixed $after = null,
        ?string $ip = null
    ): int {
        if (self::$dispatching || str_starts_with($action, 'workflow.')) {
            return 0;
        }
        try {
            $eventKey = $this->auditEventFor($action);
            if ($eventKey === '') {
                return 0;
            }
            if (!$this->mail->isReady()) {
                return 0;
            }
            $vars = $this->auditVars($eventKey, $entityType, $entityId, $before, $after, $ip);

            return $this->dispatch($eventKey, $vars, (string) ($vars['lang'] ?? 'it'), $ip);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Build the template variables for an audit-driven event. Values come from the audit payload,
     * the entity itself (member/payment) and the association settings.
     *
     * @return array<string, string>
     */
    private function auditVars(
        string $eventKey,
        string $entityType,
        ?string $entityId,
        mixed $before,
        mixed $after,
        ?string $ip
    ): array {
        $vars = $this->baseVars($eventKey, $entityType, $entityId, $ip);
        $payload = array_merge(
            $this->entityPayload($entityType, $entityId),
            $this->payloadVars($before, $after)
        );
        foreach ($payload as $key => $value) {
            if (!isset($vars[$key])) {
                $vars[$key] = $value;
            }
        }

        $id = (int) ($entityId ?? 0);
        $member = [];
        $payment = [];
        if ($eventKey === 'member.payment_received' || str_starts_with($eventKey, 'payment.')) {
            $payment = $this->paymentContext($id);
            if ($payment !== []) {
                $member = $payment['member'];
            }
        }
        if ($member === [] && $id > 0 && $entityType === 'member') {
            $member = $this->memberContext($id);
        }
        if ($member === [] && $entityType === 'member') {
            $member = $this->memberFromPayload($payload, $id);
        }
        if ($member !== []) {
            $vars = array_merge($vars, $this->memberVars($member));
            if (in_array((string) ($member['preferred_language'] ?? ''), ['it', 'de', 'en'], true)) {
                $vars['lang'] = (string) $member['preferred_language'];
            }
        }
        if ($payment !== []) {
            $vars['payment_amount'] = $this->money($payment['payment']['amount'] ?? 0);
            $vars['payment_method'] = (string) ($payment['payment']['method'] ?? '');
            $vars['payment_date'] = (string) ($payment['payment']['created_at'] ?? '');
            $vars['payment_type'] = (string) ($payment['payment']['type'] ?? '');
        }

        $this->applyEntityVars($vars, $eventKey, $payload, $member, $payment);
        $vars['email'] = $this->firstEmail([
            $vars['member_email'] ?? '',
            $vars['person_email'] ?? '',
            $vars['user_email'] ?? '',
            $vars['email'] ?? '',
            $this->setting('association.email'),
        ]);
        $vars['_to'] = $vars['email'];
        $vars['if_member_email'] = ($vars['member_email'] ?? '') !== '' ? '1' : '';

        return $vars;
    }

    /**
     * Event-specific aliases used by the email templates (document_*, deadline_*, treasury_*…).
     *
     * @param array<string,string> $vars
     * @param array<string,string> $payload
     * @param array<string,string> $member
     * @param array<string,array<string,mixed>> $payment
     */
    private function applyEntityVars(array &$vars, string $eventKey, array $payload, array $member, array $payment): void
    {
        $first = static function (array $keys) use ($payload): string {
            foreach ($keys as $key) {
                $value = trim((string) ($payload[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }

            return '';
        };
        $title = $first(['title', 'description', 'label', 'subject', 'name']);
        if ($title === '' && $member !== []) {
            $title = (string) ($member['name'] ?? '');
        }
        $date = $first(['date', 'due_date', 'movement_date', 'paid_on', 'document_date', 'created_at']);
        $amount = $first(['amount', 'balance_due', 'total']);
        $status = $first(['status']);
        $vars['entity_title'] = $title;
        $vars['entity_date'] = $date;
        $vars['entity_amount'] = $this->money($amount);
        $vars['entity_status'] = $status;

        if ($eventKey !== 'member.imported' && str_starts_with($eventKey, 'member.')) {
            $vars['member_name'] = (string) ($member['name'] ?? '');
            $vars['member_number'] = (string) ($member['member_number'] ?? '');
            $vars['member_status'] = (string) ($member['status'] ?? '');
            if ((int) ($member['id'] ?? 0) > 0 && function_exists('url')) {
                $vars['entity_url'] = url('/members/' . (int) $member['id']);
            }
        }
        if (str_starts_with($eventKey, 'member.enrollment_')) {
            $vars['enrollment_method'] = $first(['method', 'validation_method']);
        }
        if (str_starts_with($eventKey, 'payment.')) {
            $vars['payment_amount'] = $vars['payment_amount'] ?? '';
            $vars['payment_method'] = $vars['payment_method'] ?? $first(['method']);
            $vars['payment_date'] = $vars['payment_date'] ?? $date;
        }
        if (str_starts_with($eventKey, 'document.')) {
            $vars['document_title'] = $title;
            $vars['document_number'] = $first(['document_number', 'protocol_number']);
            $vars['document_category'] = $first(['category']);
            $vars['document_date'] = $first(['document_date', 'date']);
            if ((int) ($vars['entity_id'] ?? 0) > 0 && function_exists('url')) {
                $vars['entity_url'] = url('/documents/' . (int) $vars['entity_id']);
            }
        }
        if (str_starts_with($eventKey, 'deadline.')) {
            $vars['deadline_title'] = $title;
            $vars['deadline_due_date'] = $first(['due_date', 'date']);
            $vars['deadline_category'] = $first(['category']);
            $vars['deadline_status'] = $status;
        }
        if (str_starts_with($eventKey, 'treasury.')) {
            $vars['treasury_description'] = $first(['description', 'title']);
            $vars['treasury_amount'] = $this->money($first(['amount', 'total']));
            $vars['treasury_date'] = $first(['date', 'movement_date']);
            $vars['treasury_type'] = $first(['type']);
            $vars['treasury_account'] = $first(['account', 'account_slug']);
            $vars['treasury_status'] = $status;
        }
        if (str_starts_with($eventKey, 'budget.')) {
            $vars['budget_title'] = $title;
            $vars['budget_year'] = $first(['fiscal_year', 'year']);
            $vars['budget_status'] = $status;
        }
        if (str_starts_with($eventKey, 'person.')) {
            $name = trim($first(['first_name']) . ' ' . $first(['last_name']));
            $vars['person_name'] = $name !== '' ? $name : $title;
            $vars['person_email'] = $first(['email', 'person_email']);
            $vars['entity_title'] = $vars['person_name'];
        }
        if (str_starts_with($eventKey, 'org_role.')) {
            $vars['org_role_label'] = $first(['label', 'role_label']);
            $vars['org_role_key'] = $first(['key', 'role_key']);
            $vars['entity_title'] = $vars['org_role_label'];
        }
        if (str_starts_with($eventKey, 'user.')) {
            $name = trim($first(['first_name']) . ' ' . $first(['last_name']));
            $vars['user_name'] = $name !== '' ? $name : $first(['name']);
            $vars['user_email'] = $first(['email']);
            $vars['entity_title'] = $vars['user_name'];
        }
        if ($eventKey === 'member.imported') {
            $vars['import_total'] = $first(['total']);
            $vars['import_created'] = $first(['created']);
            $vars['import_updated'] = $first(['updated']);
            $vars['import_skipped'] = $first(['skipped']);
            $vars['import_failed'] = $first(['failed']);
            $vars['entity_title'] = $first(['source']);
        }
    }

    /**
     * @param array<string, string> $vars Must include recipient email as `email` or `_to`
     */
    public function dispatch(string $eventKey, array $vars, ?string $lang = null, ?string $ip = null): int
    {
        if (self::$dispatching || !$this->validEventKey($eventKey)) {
            return 0;
        }
        if (!isset($this->events()[$eventKey]) || !$this->mail->isReady()) {
            return 0;
        }
        $rules = $this->db->fetchAll(
            'SELECT * FROM workflow_rules WHERE enabled = 1 AND event_key = :e ORDER BY id ASC',
            ['e' => $eventKey]
        );
        if ($rules === []) {
            return 0;
        }
        if ($eventKey === 'member.enrollment_otp') {
            $rules = [$rules[0]];
        }
        self::$dispatching = true;
        $sent = 0;
        try {
            foreach ($rules as $rule) {
                if ($this->sendRule($rule, $vars, $lang, $ip)) {
                    $sent++;
                }
            }
        } finally {
            self::$dispatching = false;
        }

        return $sent;
    }

    /** @param array<string,mixed> $rule @param array<string,string> $vars */
    private function sendRule(array $rule, array $vars, ?string $lang, ?string $ip): bool
    {
        $to = trim((string) ($vars['email'] ?? $vars['_to'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $slug = (string) ($rule['template_slug'] ?? '');
        $tpl = $this->templates->findBySlug($slug);
        if (!$tpl) {
            return false;
        }
        $lang = $this->templates->normalizeLang($lang ?? ($vars['lang'] ?? 'it'));
        $rendered = $this->templates->renderTemplate($tpl, $lang, $vars);
        $html = ($rendered['body_format'] ?? 'text') === 'html';
        $body = $rendered['body'];
        $plain = $html ? trim(strip_tags(preg_replace('/<\s*br\s*\/?>/i', "\n", $body) ?? $body)) : $body;
        $result = $this->mail->send($to, $rendered['subject'], $plain, $html ? $body : null);
        if (!empty($result['ok'])) {
            $this->audit->log('workflow.sent', 'workflow', (string) ($rule['id'] ?? ''), null, [
                'event' => (string) ($rule['event_key'] ?? ''),
                'template' => $slug,
                'to' => $to,
            ], $ip ?? '');
            return true;
        }
        return false;
    }

    /** @return array<string,string> */
    private function baseVars(string $eventKey, string $entityType, ?string $entityId, ?string $ip): array
    {
        $actor = $this->actorVars();

        return [
            'event' => $eventKey,
            'event_label' => $this->eventLabel($eventKey),
            'entity_type' => $entityType,
            'entity_id' => (string) ($entityId ?? ''),
            'association_name' => $this->setting('association.name'),
            'association_email' => $this->setting('association.email'),
            'operator_name' => $actor['name'],
            'operator_email' => $actor['email'],
            'lang' => $this->defaultLang(),
            'ip' => (string) ($ip ?? ''),
        ];
    }

    /** @return array{name:string,email:string} */
    private function actorVars(): array
    {
        try {
            $user = auth_user();
        } catch (\Throwable) {
            return ['name' => '', 'email' => ''];
        }
        $name = trim((string) ($user['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
        }

        return ['name' => $name, 'email' => (string) ($user['email'] ?? '')];
    }

    private function eventLabel(string $eventKey): string
    {
        $coreLabel = self::coreEvents()[$eventKey] ?? '';
        if ($coreLabel !== '') {
            try {
                return (string) __($coreLabel);
            } catch (\Throwable) {
                return $coreLabel;
            }
        }
        $events = $this->events();

        return (string) ($events[$eventKey] ?? $eventKey);
    }

    private function defaultLang(): string
    {
        try {
            return (string) (config('app.locale', 'it') ?: 'it');
        } catch (\Throwable) {
            return 'it';
        }
    }

    /** @param array<string,mixed> $member @return array<string,string> */
    private function memberVars(array $member): array
    {
        return [
            'member_id' => (string) ($member['id'] ?? ''),
            'member_email' => (string) ($member['email'] ?? ''),
            'member_name' => (string) ($member['name'] ?? ''),
            'member_number' => (string) ($member['member_number'] ?? ''),
            'member_status' => (string) ($member['status'] ?? ''),
            'payment_amount' => $this->money($member['balance_due'] ?? 0),
        ];
    }

    /**
     * A deleted member has no row anymore: rebuild the contact data from the audit payload.
     *
     * @param array<string,string> $payload
     * @return array<string,mixed>
     */
    private function memberFromPayload(array $payload, int $memberId): array
    {
        $first = trim((string) ($payload['fields_first_name'] ?? $payload['first_name'] ?? ''));
        $last = trim((string) ($payload['fields_last_name'] ?? $payload['last_name'] ?? ''));
        $email = strtolower(trim((string) ($payload['fields_email'] ?? $payload['email'] ?? '')));
        if ($first === '' && $last === '' && $email === '') {
            return [];
        }
        $name = trim($first . ' ' . $last);
        if ($name === '') {
            $name = trim((string) ($payload['member_number'] ?? ''));
        }

        return [
            'id' => $memberId,
            'member_number' => (string) ($payload['member_number'] ?? ''),
            'balance_due' => $payload['balance_due'] ?? '',
            'status' => (string) ($payload['status'] ?? ''),
            'name' => $name,
            'email' => $email,
            'preferred_language' => (string) ($payload['fields_preferred_language'] ?? $payload['preferred_language'] ?? ''),
        ];
    }

    /**
     * Member contact data used by the audit bridge. The email and names live in the EAV fields.
     *
     * @return array<string,mixed>
     */
    private function memberContext(int $memberId): array
    {
        if ($memberId < 1) {
            return [];
        }
        try {
            $row = $this->db->fetch(
                "SELECT m.id, m.member_number, m.balance_due, m.status,
                        COALESCE((SELECT v.value FROM member_field_values v
                                  INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'first_name'
                                  WHERE v.member_id = m.id LIMIT 1), '') AS first_name,
                        COALESCE((SELECT v.value FROM member_field_values v
                                  INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'last_name'
                                  WHERE v.member_id = m.id LIMIT 1), '') AS last_name,
                        COALESCE((SELECT v.value FROM member_field_values v
                                  INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'email'
                                  WHERE v.member_id = m.id LIMIT 1), '') AS email,
                        COALESCE((SELECT v.value FROM member_field_values v
                                  INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'preferred_language'
                                  WHERE v.member_id = m.id LIMIT 1), '') AS preferred_language
                 FROM members m WHERE m.id = :id LIMIT 1",
                ['id' => $memberId]
            );
        } catch (\Throwable) {
            return [];
        }
        if ($row === null) {
            return [];
        }
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['member_number'] ?? ''));
        }
        $row['name'] = $name;
        $row['email'] = strtolower(trim((string) ($row['email'] ?? '')));

        return $row;
    }

    /**
     * @return array{payment:array<string,mixed>,member:array<string,mixed>}|array{}
     */
    private function paymentContext(int $paymentId): array
    {
        if ($paymentId < 1) {
            return [];
        }
        try {
            $payment = $this->db->fetch('SELECT * FROM payments WHERE id = :id LIMIT 1', ['id' => $paymentId]);
        } catch (\Throwable) {
            return [];
        }
        if ($payment === null) {
            return [];
        }

        return [
            'payment' => $payment,
            'member' => $this->memberContext((int) ($payment['member_id'] ?? 0)),
        ];
    }

    /**
     * Current row of the entity, used when the audit payload is empty (e.g. deadline.done).
     *
     * @return array<string,string>
     */
    private function entityPayload(string $entityType, ?string $entityId): array
    {
        $id = (int) ($entityId ?? 0);
        if ($id < 1) {
            return [];
        }
        $table = match ($entityType) {
            'document' => 'association_documents',
            'deadline' => 'deadline_items',
            'treasury' => 'treasury_movements',
            'budget' => 'budgets',
            'person' => 'association_people',
            'user' => 'users',
            default => '',
        };
        if ($table === '') {
            return [];
        }
        try {
            $row = $this->db->fetch('SELECT * FROM ' . $table . ' WHERE id = :id LIMIT 1', ['id' => $id]);
        } catch (\Throwable) {
            return [];
        }

        return is_array($row) ? $this->flatten($row) : [];
    }

    /** @return array<string,string> */
    private function payloadVars(mixed $before, mixed $after): array
    {
        $out = [];
        foreach ([$before, $after] as $payload) {
            if (!is_array($payload)) {
                continue;
            }
            foreach ($this->flatten($payload) as $key => $value) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Flatten an audit payload to scalar template variables; nested `fields` become fields_email…
     *
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    private function flatten(array $row, string $prefix = '', int $depth = 0): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $name = strtolower((string) $key);
            if ($name === '' || $this->isSensitiveKey($name)) {
                continue;
            }
            if (is_array($value)) {
                if ($depth < 2) {
                    $out += $this->flatten($value, $prefix . $name . '_', $depth + 1);
                }
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '';
            }
            if ($value === null || is_object($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }
            $out[$prefix . $name] = mb_substr($text, 0, 500);
        }

        return $out;
    }

    private function isSensitiveKey(string $key): bool
    {
        foreach ([
            'password', 'passwd', 'secret', 'token', 'otp', 'hash',
            'fiscal_code', 'codice_fiscale', 'app_key', 'iban', 'card_number',
        ] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $candidates */
    private function firstEmail(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $email = strtolower(trim((string) $candidate));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return '';
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_numeric($value)) {
            return mb_substr(trim((string) $value), 0, 60);
        }

        return number_format((float) $value, 2, ',', '.') . ' €';
    }

    private function setting(string $key, string $default = ''): string
    {
        try {
            return (string) app(\Socly\Services\SettingsService::class)->get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
