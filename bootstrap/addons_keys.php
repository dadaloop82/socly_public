<?php

declare(strict_types=1);

/**
 * Public keys used to verify Socly add-ons: licences and packages.
 *
 * Keep this file aligned with the site keys (admin → Add-on → Chiavi di firma).
 * Private keys never leave the server.
 *
 * Current values (production):
 * - `license` — socly.it licence signing key (private key on the site server in
 *   `_webSite/storage/keys/license_ed25519.key`);
 * - `package` — add-on package signing key (private key `storage/addons/keys/package_ed25519.key`
 *   on the vendor host; packages are built there or on a machine that holds that file).
 * Every instance trusts these two public keys: the site public keys file and this file
 * must contain the same values.
 *
 * @return array<string, array{kid:string, public_key:string}>
 */
return [
    'license' => [
        'kid' => 'socly-lic-1',
        'public_key' => '-FLaON0r3IswO_1M5P8RAtkhXSd6uLqZ0JM1pYxcfus',
    ],
    'package' => [
        'kid' => 'socly-pkg-1',
        'public_key' => 'LvWTSFUGpvBLB0ESVlEppomRcGJBeERZq-EFZ_dRLF4',
    ],
];
