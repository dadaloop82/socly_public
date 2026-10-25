<?php

declare(strict_types=1);

// Router script for PHP built-in server
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

// Marker used by the one-click installers to tell the Socly built-in server
// apart from any other process that happens to answer on the chosen port.
header('X-Socly-Installer: 1');

require __DIR__ . '/index.php';
