<?php

declare(strict_types=1);

/**
 * Public keys used to verify Socly add-ons: licences and packages.
 *
 * Keep this file aligned with the site keys (admin → Add-on → Chiavi di firma).
 * Private keys never leave the server.
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
