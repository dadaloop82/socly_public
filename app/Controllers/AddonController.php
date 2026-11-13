<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Addon\AddonKeys;
use Socly\Core\Addon\AddonManager;
use Socly\Core\Addon\LicenseService;
use Socly\Core\Http\Request;
use Socly\Core\View;

/**
 * Paid add-ons: signed licences, offline activation codes, package install/update.
 */
final class AddonController extends BaseController
{
    public function __construct(
        View $view,
        private readonly AddonManager $addons,
        private readonly LicenseService $license
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        // Keep licensed packages installed and updated: activation alone never leaves
        // the add-on half-configured (at most one catalogue check per hour).
        $auto = $this->addons->autoInstall($request->ip());
        $installed = $this->addons->installed();
        $requested = [];
        $rawRequest = trim((string) $request->input('request', ''));
        foreach (explode(',', $rawRequest) as $candidate) {
            $candidate = trim($candidate);
            if (preg_match('/^[a-z0-9][a-z0-9_.-]{2,80}$/', $candidate) === 1) {
                $requested[$candidate] = $candidate;
            }
        }
        if ($requested === []) {
            foreach ($installed as $row) {
                $requested[(string) $row['id']] = (string) $row['id'];
            }
        }
        // An online licence carries the signed catalogue: autoInstall() above already
        // refreshed it, so the catalogue rows can be built without another network call.
        $entitlement = $this->license->entitlement();

        $this->render('addons/index', [
            'title' => __('addons.title'),
            'installed' => $installed,
            'auto' => $auto,
            'entitlement' => $entitlement,
            'entitlement_payload' => $entitlement?->payload() ?? [],
            'license_key_present' => $this->license->licenseKey() !== '',
            'revoked' => $this->license->isRevoked(),
            'instance_code' => $this->license->instanceCode(),
            'host' => $this->license->host(),
            'core_version' => app_version(),
            'request_code' => $this->license->requestCode(array_values($requested)),
            'request_ids' => array_values($requested),
            'license_kid' => AddonKeys::kid('license'),
            'package_kid' => AddonKeys::kid('package'),
            'keys_ready' => AddonKeys::publicKey('license') !== '' && AddonKeys::publicKey('package') !== '',
            'incoming' => $this->addons->discoverIncoming(),
            'incoming_dir' => 'storage/addons/incoming',
            'catalogue' => $this->addons->availableFromLicence(),
        ]);
    }

    public function activate(Request $request): void
    {
        $key = trim((string) $request->input('license_key', ''));
        $result = $this->license->activateOnline($key, $request->ip());
        if (empty($result['ok'])) {
            $this->flash('errors', ['addons' => (string) ($result['error'] ?? __('addons.error_online'))]);
            redirect('/settings/addons');
        }
        // Paid = ready: download and enable the licensed packages straight away.
        $auto = $this->addons->autoInstall($request->ip(), true);
        $message = __('addons.activated', ['addons' => implode(', ', $result['addons'] ?? [])]);
        if (!empty($auto['installed'])) {
            $message .= ' · ' . __('addons.auto_installed', ['list' => $this->describeInstalled($auto['installed'])]);
        } elseif (!empty($auto['pending'])) {
            $message .= ' · ' . $this->describePending($auto['pending']);
        }
        $this->flash('success', $message);
        if (!empty($auto['errors'])) {
            $this->flash('errors', ['addons' => implode(' · ', array_map('strval', $auto['errors']))]);
        }
        redirect('/settings/addons');
    }

    /** Manual “Installa ora”: force the catalogue check and the package installs. */
    public function autoInstallAction(Request $request): void
    {
        $result = $this->addons->autoInstall($request->ip(), true);
        if (!empty($result['installed'])) {
            $this->flash('success', __('addons.auto_installed', ['list' => $this->describeInstalled($result['installed'])]));
        }
        if (!empty($result['pending'])) {
            $this->flash('errors', ['addons' => $this->describePending($result['pending'])]);
        }
        if (!empty($result['errors'])) {
            $this->flash('errors', ['addons' => implode(' · ', array_map('strval', $result['errors']))]);
        }
        if (empty($result['installed']) && empty($result['pending']) && empty($result['errors'])) {
            $this->flash('success', ($result['reason'] ?? '') === 'offline'
                ? __('addons.error_offline_only')
                : __('addons.auto_none'));
        }
        redirect('/settings/addons');
    }

    public function import(Request $request): void
    {
        $code = trim((string) $request->input('code', ''));
        $result = $this->license->import($code, $request->ip());
        if (empty($result['ok'])) {
            $this->flash('errors', ['addons' => (string) ($result['error'] ?? __('addons.error_code_invalid'))]);
        } else {
            $this->flash('success', __('addons.imported', ['addons' => implode(', ', $result['addons'] ?? [])]));
        }
        redirect('/settings/addons');
    }

    public function install(Request $request): void
    {
        $file = $request->file('package');
        if (!is_array($file)) {
            $this->flash('errors', ['addons' => __('addons.error_no_file')]);
            redirect('/settings/addons');
        }
        $this->flashInstall($this->addons->installUpload($file, $request->ip()));
        redirect('/settings/addons');
    }

    /** Install a package already present in storage/addons/incoming (no upload). */
    public function installIncoming(Request $request): void
    {
        $result = $this->addons->installFromIncoming((string) $request->input('file', ''), $request->ip());
        $this->flashInstall($result);
        redirect('/settings/addons');
    }

    /** Install/update a licensed add-on from the signed catalogue. */
    public function installLicensed(Request $request): void
    {
        $result = $this->addons->installFromLicence(
            (string) $request->input('addon_id', ''),
            (string) $request->input('version', ''),
            $request->ip()
        );
        $this->flashInstall($result);
        redirect('/settings/addons');
    }

    public function check(Request $request): void
    {
        $result = $this->addons->checkUpdates($request->ip());
        if (empty($result['ok'])) {
            $this->flash('errors', ['addons' => (string) ($result['error'] ?? __('addons.error_online'))]);
            redirect('/settings/addons');
        }
        $updated = is_array($result['updated'] ?? null) ? $result['updated'] : [];
        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        if ($errors !== []) {
            $this->flash('errors', ['addons' => implode(' · ', $errors)]);
        }
        if ($updated === []) {
            $this->flash('success', __('addons.updates_none'));
        } else {
            $parts = [];
            foreach ($updated as $row) {
                $parts[] = $row['id'] . ' ' . $row['from'] . ' → ' . $row['to'];
            }
            $this->flash('success', __('addons.updated', ['list' => implode(', ', $parts)]));
        }
        redirect('/settings/addons');
    }

    public function enable(Request $request, string $id): void
    {
        $result = $this->addons->enable($id, $request->ip());
        $this->flashResult($result, __('addons.enabled', ['addon' => $id]));
        redirect('/settings/addons');
    }

    public function disable(Request $request, string $id): void
    {
        $result = $this->addons->disable($id, $request->ip());
        $this->flashResult($result, __('addons.disabled', ['addon' => $id]));
        redirect('/settings/addons');
    }

    public function remove(Request $request, string $id): void
    {
        $result = $this->addons->remove($id, $request->ip());
        $this->flashResult($result, __('addons.removed', ['addon' => $id]));
        redirect('/settings/addons');
    }

    /** @param list<array{id:string,from:string,to:string}> $rows */
    private function describeInstalled(array $rows): string
    {
        $parts = [];
        foreach ($rows as $row) {
            $parts[] = $row['id'] . ' ' . $row['to'];
        }

        return implode(', ', $parts);
    }

    /** @param list<array{id:string,min_core:string}> $rows */
    private function describePending(array $rows): string
    {
        $parts = [];
        foreach ($rows as $row) {
            $parts[] = $row['id'] . ' (core ≥ ' . $row['min_core'] . ')';
        }

        return __('addons.auto_pending', ['list' => implode(', ', $parts)]);
    }

    /** @param array{ok:bool,error?:string,id?:string,version?:string,replaced?:bool} $result */
    private function flashInstall(array $result): void
    {
        if (empty($result['ok'])) {
            $this->flash('errors', ['addons' => (string) ($result['error'] ?? __('addons.error_package_invalid'))]);
            return;
        }
        $this->flash('success', __('addons.installed', [
            'addon' => (string) ($result['id'] ?? ''),
            'version' => (string) ($result['version'] ?? ''),
        ]));
    }

    /** @param array{ok:bool,error?:string} $result */
    private function flashResult(array $result, string $success): void
    {
        if (empty($result['ok'])) {
            $this->flash('errors', ['addons' => (string) ($result['error'] ?? __('addons.error_generic'))]);
            return;
        }
        $this->flash('success', $success);
    }
}
