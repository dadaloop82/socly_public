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
| Private repo (`tools/addons/`) | key generation, package builder/signer, licence signing CLI, guard tests |
| socly.it (`_webSite`) | catalogue, signed package storage, licence issuance/revocation, `/api/addons.php` |
| Instance storage | `storage/addons/<id>/<version>/` (code), settings (signed entitlement, encrypted), `storage/addons/keys` (package private key in the private repo only) |

The private keys never belong to the public tree:

- `storage/addons/keys/package_ed25519.key` — signs `.soclyaddon` packages (vendor/CI only).
- `_webSite/storage/keys/license_ed25519.key` — signs licences and the update manifest (socly.it).
- `bootstrap/addons_keys.php` — **public** keys only, shipped with the core.
- `_webSite/storage/keys/addons_public_keys.php` — copy of the public keys used by socly.it.

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

## Instance workflow

Settings → Add-ons (`/settings/addons`, permission `addons.manage`):

- **Online activation** — paste the licence key; the instance calls socly.it, stores the signed token
  encrypted and downloads the signed catalogue.
- **Offline activation** — paste the `.soclylic` code; no network call, no data sent.
- **Request code** — copy the generated `SOCLY-REQ1…` and send it to the vendor for an offline licence.
- **Install / update package** — upload a `.soclyaddon`; signature, hashes, `php -l`, migrations and
  activation run server-side. Older versions stay on disk as a backup.
- **Check updates** — with an online licence, verifies the signed catalogue, downloads newer versions
  and installs them (audit `addon.updated`). Offline installations update by uploading the package.
- **Disable / remove** — disabling keeps files and data; removal is allowed only when disabled and keeps
  the database tables (data is never destroyed automatically).

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
