#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Thin wrapper — prefer _webSite/bin/demo-migrate.php (hosted site pull).
 *
 * @deprecated Use _webSite/bin/demo-migrate.php directly.
 */

$target = dirname(__DIR__) . '/_webSite/bin/demo-migrate.php';
if (!is_file($target)) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => 'Missing _webSite/bin/demo-migrate.php'], JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

require $target;
