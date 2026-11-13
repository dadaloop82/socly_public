# Paid add-ons

Paid extensions are **signed PHP packages** that the core verifies locally. The public product
repository never contains paid add-on code: it only carries the verifier, the public keys and this
document. Licences, packages and updates are distributed by socly.it (`/admin`) and verified on the
instance with Ed25519, also without any internet connection.

## Decisions (current product model)

| Topic | Decision |
|---|---|
| Price | One-off (perpetual licence) |
| Licence | One licence = one installation (an association with several seats uses the same instance) |
| Offline codes | Perpetual, signed; no network required |
| Add-ons | Multi-file packages with one PHP entry point implementing `PluginInterface` |
| Updates | The vendor builds and publishes new signed versions; the instance updates online or by uploading the new package |
| Grants | The vendor decides manually in `socly.it/admin` (payments are handled outside the product) |

## Where the code lives

| Location | Content |
|---|---|
| Public repo (`socly_public`) | `app/Core/Addon/*` (verifier only), `bootstrap/addons_keys.php` (**public** keys), docs, migration `048_addons.sql` |
| Private repo (`tools/addons/`, `addons-src/`) | key generation, package builder/signer, licence signing CLI, guard tests, add-on sources |
| socly.it (`_webSite`) | catalogue, signed package storage, licence issuance/revocation, `/api/addons.php` |
| Instance storage | `storage/addons/<id>/<version>/` (code), settings (signed entitlement, encrypted), `storage/addons/keys` (package private key in the private repo only) |

The private keys never belong to the public tree:

- `_webSite/storage/keys/license_ed25519.key` — **production socly.it** signing key (licences + update manifest).
- `storage/addons/keys/package_ed25519.key` — package signing key. It must be the private half of the
  `package` public key in `bootstrap/addons_keys.php`; packages are built on the host that holds it
  (vendor/CI, e.g. the socly.it host) and never on a machine without it.
- `bootstrap/addons_keys.php` — **public** keys shipped with the core: production licence key and package key.
  Every instance trusts these two.
- `_webSite/storage/keys/addons_public_keys.php` — copy of the public keys used by socly.it (same two values).

Keep the site file aligned with the core file: admin → Add-on → «Inizializza chiavi» derives the licence public
key from the private one and writes the site file; the package public key must stay the package-key one.
Verify the alignment on the host that holds the private keys with:

```bash
php tools/addons/addon-tool.php key-status
```

Both rows must report `allineata al core: sì`. On a machine without the production private keys the licence
row (and possibly the package row) shows `no`: build/sign licences on socly.it instead.

## Formats

### Entitlement (licence token)

```
SOCLY-ENT1.<base64url(payload JSON)>.<base64url(Ed25519 signature)>
```

The signature covers the literal `SOCLY-ENT1.<payload segment>`, so the prefix cannot be swapped.
Payload:

```json
{
  "v": 1,
  "lic": "lic_…",
  "iid": "478cab5f58af8633",
  "host": "assoc.example.it",
  "iat": "2026-11-12T21:00:00+00:00",
  "exp": "2027-11-12T21:00:00+00:00",
  "addons": [{"id": "advanced_reports"}]
}
```

- `iid` = first 16 hex of `sha256(platform.instance_token)`; it is stable, non-reversible and does not
  reveal the association.
- `exp` is optional (perpetual licences omit it).
- The instance verifies the signature with `bootstrap/addons_keys.php` and refuses a token issued for
  another `iid`.

### Request code (offline sale)

```
SOCLY-REQ1.<base64url({"iid":"…","host":"…","core":"1.7.81","addons":["…"],"nonce":"…"})>
```

Unsigned on purpose: it only carries the instance identity and the requested add-ons. The vendor pastes
it into the admin licence form to bind the licence; the real security is the signed entitlement.

### Package (`.soclyaddon`)

A zip with:

```
addon.json          manifest: id, name, version, min_core, entry, files{path: sha256}
addon.json.sig      detached Ed25519 signature of the exact addon.json bytes
plugin.php          entry point returning a PluginInterface (multi-file via lib/…)
```

Install rules: signature valid, every declared file hash matches, no undeclared files, safe relative
paths only, extension allowlist, `min_core` compatible, PHP files pass `php -l`. The package is
extracted atomically under `storage/addons/<id>/<version>/` (outside the document root, preserved by
core updates) and the previous version is kept as a rollback copy.

### Manifest (updates)

`POST /api/addons.php action=manifest` returns `{manifest: "<json string>", signature: "<b64url>"}`.
The signature is made with the **licence** key and covers the exact JSON string. Content:

```json
{"v":1,"generated_at":"…","addons":{"advanced_reports":{"latest":"1.2.0","min_core":"1.7.70",
 "versions":[{"version":"1.2.0","sha256":"…","url":"https://socly.it/api/addons.php?action=download&addon=advanced_reports&version=1.2.0"}]}}}
```

## Admin UI (socly.it)

| Page | What you do |
|---|---|
| `/admin/addons.php` | **Chiavi di firma** with the «Inizializza chiavi» button (creates `storage/keys/license_ed25519.key` when missing and writes `storage/keys/addons_public_keys.php`, so no CLI is needed), catalogue (id/name/description), signed package upload with signature + hash verification, per add-on detail (current version, entry point, declared files, versions with download, licences attached) |
| `/admin/licenses.php` | **New licence** from an existing Socly installation (iid/host/customer filled automatically), from a `SOCLY-REQ1…` request code, or with a manual `iid` for offline installations; licence manager with filters (search, add-on, status, bound/unbound), per add-on coverage ("who has what"), licence detail with add-on/expiry editing, installation (re)binding, offline `.soclylic` code and event log |
| `/admin/installations.php` | add-on column and detail row: which add-ons are licensed per installation, linking to the filtered licence list |

How an add-on is "attached": the catalogue id **is** the `id` inside `addon.json`. The signed package
uploaded for that id is stored under `storage/addons/packages/<id>/<version>.soclyaddon`; the licence
grants `<id>` to one installation (`iid` = first 16 hex of `sha256(instance token)` = first 16 hex of the
stored `token_hash`) and the signed manifest serves that package to the instance. The public site never
needs the private repository: it only needs the signed package and the public verification keys.

## Vendor workflow

```bash
php tools/addons/addon-tool.php keygen          # only when the site cannot use the admin button
php tools/addons/addon-tool.php build ./addon-src ./dist/advanced_reports-1.2.0.soclyaddon
php tools/addons/addon-tool.php verify-package ./dist/advanced_reports-1.2.0.soclyaddon
php tools/addons/addon-tool.php sign-license payload.json --out=license.txt   # only if socly.it is down
```

1. **socly.it → Add-on → Chiavi di firma**: click «Inizializza chiavi» (idempotent, never rotates
   existing keys). If the licence keypair is new, copy the generated `bootstrap/addons_keys.php` snippet
   into the core repo and release a core version; the package public key must stay the one used by the
   build machine.
2. **socly.it → Add-on → Catalogo**: register the add-on with the same id used in `addon.json`.
3. **socly.it → Add-on → Pacchetto firmato**: upload the `.soclyaddon`; signature and per-file hashes are
   verified before publication under `storage/addons/packages/<id>/<version>.soclyaddon`.
4. **socly.it → Licenze → Nuova licenza**: pick an existing installation (online activation), paste a
   request code, or type the `iid` for an offline installation; choose the add-ons.
5. Send the customer the **licence key** (`SOCLY-KEY-…`) for online activation, or the **offline code**
   (`.soclylic` download) plus the package file.
6. Revoke/reactivate from the licence detail; every event is logged in `license_events`.

## First add-on: Iscrizione Tablet (`tablet_enrollment`)

Source: `addons-src/tablet_enrollment/` (private repo; `addons-src/` is not part of the public export).
Requires core **1.7.88+** (public plugin routes with a middleware override + `addon_active()`).

| File | Purpose |
|---|---|
| `addon.json` | id/name/version/min_core/entry consumed by the build tool |
| `plugin.php` | `PluginInterface`: public kiosk route, admin page + menu, migration |
| `lib/KioskService.php` | token gate, session wizard, validation, member/payment/card creation |
| `lib/AddonText.php` | it/de/en strings shipped with the package |
| `views/*.php` | branded layout, wizard steps, admin panel, error page |

Behaviour:

- **Core gating** — the “Tablet” enrollment validation (`tablet_sign`) is selectable only when
  `addon_active('tablet_enrollment')` is true. Setup and settings reject it otherwise; if the
  add-on is removed/disabled/unlicensed, `EnrollmentService::method()` falls back to `none` so
  member creation is never blocked by a method that no longer exists.
- **Kiosk** — `GET|POST /tablet/<token>`, public (no operator login), CSRF + access token in the
  path. Steps: Tessera (type + period + optional existing card number) → Dati (all enabled member
  fields, dynamically rendered) → Firma (core signature pad + acknowledgements) → Pagamento
  (paid/partial/unpaid, method, note, summary) → riepilogo with member number and card.
- **Writes** go through `MemberService::create()` (payment row, balance, treasury, audit, hooks)
  and `EnrollmentService::storeArtifact()` (signed PNG); the add-on issues the
  `membership_cards` row and logs every sign-up in `addon_tablet_enrollment_log`.
- **Admin** — `/tablet-enrollment` (menu entry, `settings.manage`): kiosk URL, status, card-number
  mode (`auto` = member number, `existing` = ask for the printed card) and the latest sign-ups.
- **Security** — 24 random bytes token stored encrypted and rotatable from the admin page,
  per-IP rate limit, `noindex`, no member list exposed by the kiosk.

Build and publish (private tooling):

```bash
php tools/addons/addon-tool.php build addons-src/tablet_enrollment dist/tablet_enrollment-1.0.0.soclyaddon --incoming
php tools/addons/addon-tool.php verify-package dist/tablet_enrollment-1.0.0.soclyaddon
```

`--incoming` also copies the package into `storage/addons/incoming/` of the **instance** (Settings → Add-ons
verifies it and lists it in “Add-on disponibili”); `--site` copies it into `_webSite/storage/addons/incoming/`
of the **socly.it** catalogue (Admin → Add-on → Pubblica). On another server, copy the file into the matching
folder manually. A licence containing the add-on is still required: activate it first (online key, offline
code or request code), then install from the drop folder or from the licence catalogue.

## Instance workflow

Settings → Add-ons (`/settings/addons`, permission `addons.manage`):

- **Licence ≠ package** — activating a licence stores the entitlement; an online activation also
  downloads and enables the licensed packages, while an offline `.soclylic` code installs the
  package from the drop folder or manual upload. Until the package is installed the menus/routes
  of the add-on do not exist and the core features gated on it stay off.
- **Add-on disponibili** — the page merges three sources into one plain-language table: the licensed
  catalogue downloaded from socly.it, the packages found in `storage/addons/incoming/` and the installed
  rows. Each row shows the state (`Da installare`, `Aggiornamento disponibile`, `Installato, da attivare`,
  `Serve Socly x.y.z`, `Licenza richiesta`, `Pacchetto non valido`) and the matching action, so the
  administrator never has to understand portals, folders or manifests.
- **Automatic install/update** — activating a licence fetches the signed manifest and installs/enables the
  licensed packages automatically; the Add-ons page repeats the check at most once per hour, and
  `php bin/addons-update.php` (cron) does the same without opening the UI. The manifest is cached and
  the package is downloaded server-side, hash-checked and signature-checked before installation.
- **Offline installations** — everything technical lives under “Opzioni avanzate”: offline activation
  (`.soclylic` code, no network call, no data sent), request code (`SOCLY-REQ1…` to send to the vendor),
  manual `.soclyaddon` upload, drop folder (`storage/addons/incoming/`), instance code and verification
  keys. Packages dropped there are verified (signature, hashes, `min_core`) and appear in the same
  “Add-on disponibili” table; `php tools/addons/addon-tool.php build … --incoming` copies them there.
- **Check updates** — with an online licence, verifies the signed catalogue, downloads newer versions
  and installs them (audit `addon.updated`). Offline installations update through the drop folder.
- **Disable / remove** — disabling keeps files and data; removal is allowed only when disabled and keeps
  the database tables (data is never destroyed automatically).

## Error reporting

`LicenseService::post()` separates a real network failure from an API refusal: only DNS, TLS, timeout
or refused connections read “the licence service is unreachable from this server”, while an HTTP 4xx/5xx
that carries the site JSON is mapped to an actionable message (invalid key, revoked, expired, bound to
another installation, installation not recognised) that points the customer to the Socly team
(`info@socly.it`) — never to the website as if it managed the licence. Unmapped refusals keep the site
wording plus the HTTP status (`error_api_rejected`), and a non-JSON body reports `error_api_response`
with the status. The licence key never appears in a message or in the log.

## When an add-on breaks the panel

- `php bin/addons-toggle.php` lists the installed add-ons with their state; `php bin/addons-toggle.php
  <id> off` disables one from the shell (files and data stay), `on` re-enables it (an active licence is
  required). It is the rescue tool when the browser UI cannot be opened.
- The panel isolates failures: a drop-in plugin or add-on that throws while loading or booting is logged
  (`plugin.boot_failed`) and skipped for that request instead of taking every page down; `failedPlugins()`
  lists them.
- Errors keep a reference code. Signed-in administrators see the technical block (type, file:line,
  request, message) on the error page; `SOCLY_SHOW_ERRORS=1` in `.env` shows it to anyone for support.
- Every crash is reported automatically through the relay (`App::run()` and the global exception
  handler), and the site stores it in `bug_reports` (Admin → Reports) **before** trying GitHub: a
  disabled relay or a revoked token no longer hides crashes. The error page shows the message and
  whether the report went out (`SOCLY_REPORT_PROBLEMS=0` disables sending on the instance).

## Package lint and PHP-FPM

Packages are linted with `php -l` before installation. Under PHP-FPM `PHP_BINARY` points at the FPM
binary, which has no `-l` switch (it answers with its usage screen), so the instance resolves a CLI
binary first: `SOCLY_PHP_BIN` from `.env` when set, the CLI binary, `PHP_BINDIR/php`, then `php` from
`PATH`. If no usable CLI exists — or `exec()` is disabled on hardened hosting — the lint is skipped
on purpose: the signature is the real boundary, the lint is only a safety net.

## Licence management (socly.it admin)

`/admin/licenses.php` → licence detail:

- **Sblocca licenza** — clears the installation binding (`installation_token_hash`, `installation_code`,
  host, last activation): the key can be activated on another installation and, once a new installation
  is bound, the offline code is rebuilt. Use it after a reinstall or when the instance answers “already
  activated on another installation”.
- **Rigenera chiave** — replaces the `SOCLY-KEY-…` activation key; the previous one stops working
  immediately while the installation binding and the offline `SOCLY-ENT1…` code stay valid.
- **Elimina licenza** — deletes the licence with its add-on links and event history (irreversible):
  key and offline code stop working at once. Installations and packages are not touched.

The instance refuses the two codes in the wrong field with a dedicated message (`error_key_is_offline_code`
and `error_code_is_online_key`), so the operator is sent to the right form instead of reading “key not
recognised”.

## API (socly.it)

| Method | Action | Notes |
|---|---|---|
| `POST` | `activate` | body: instance `token`, `iid`, `license_key`, `host`, `core_version`; binds an unbound licence on first use; returns entitlement + signed manifest |
| `POST` | `status` | returns `revoked: true/false`; revocation clears the entitlement on the instance at the next check |
| `POST` | `manifest` | signed catalogue of the licensed add-ons |
| `GET` | `download&addon=&version=` | `Authorization: Bearer <licence key>`; streams the package; verifies licence and version |

Only `https` download URLs are accepted by the instance. Associate the API host with the licence
authority and keep `SOCLY_ADDONS_API_URL` (`.env`, optional) pointing to socly.it.

## Production checklist

1. **Create/verify the keys from the admin UI** (`/admin/addons.php` → «Inizializza chiavi»), which writes
   `_webSite/storage/keys/addons_public_keys.php` on the server. Back up both private keys offline; losing
   them means you can no longer sign licences or packages. Use `keygen --force` only for a deliberate
   rotation, then update `bootstrap/addons_keys.php` with the snippet shown in the page and release a core
   version.
2. Commit the generated `bootstrap/addons_keys.php`. The site copy
   (`_webSite/storage/keys/addons_public_keys.php`) is generated on the server and stays in
   `storage/` (never committed); never commit the private keys.
3. `storage/addons` and `_webSite/storage` must stay outside the document root (the site `.htaccess`
   denies `storage/`, but an nginx deployment must deny it too).
4. Keep `UPDATE_ENABLED`/core updates working: `storage/` is preserved by the updater, so packages and
   licences survive core updates.
5. Run `php tools/checks/addons-guard.php` in CI (private repo) on every change to the add-on layer.

## Security and honest limits

- Add-ons are **signed**; the instance refuses modified or unsigned packages. PHP code is readable, so a
  determined customer can copy it: the licence is what keeps updates and support tied to a paying
  installation, not a DRM. Do not promise otherwise in the contract.
- Offline licences are perpetual and cannot be revoked remotely. Revocation works for online licences
  (next status check) and stops new codes/packages; an offline code already delivered remains valid.
- The instance stores only the signed token (encrypted) and the licence key (encrypted); no association
  data is sent by the add-on layer. Online activation sends only `iid`, host, core version and licence id.
- Private keys are the real secret. Generate them on a trusted machine, back them up offline, and rotate
  by adding a new `kid`/public key to `bootstrap/addons_keys.php` in a core release.
