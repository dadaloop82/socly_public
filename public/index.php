<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$app = require dirname(__DIR__) . '/bootstrap/app.php';

$debug = (bool) $app->config('app.debug', false);

set_exception_handler(static function (Throwable $e) use ($app, $debug): void {
    $errorCode = 'SOC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $errorTime = date('c');
    $errorType = $e::class;
    $errorTypeShort = basename(str_replace('\\', '/', $errorType));
    $errorFile = basename($e->getFile());
    $errorLine = $e->getLine();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $errorRequest = $method . ' ' . $uri;
    $rawMessage = trim($e->getMessage());
    // Keep a short, redacted message for the UI.
    $safeMessage = preg_replace('/(password|token|secret|key)=([^\s&]+)/i', '$1=[redacted]', $rawMessage) ?? $rawMessage;
    if (mb_strlen($safeMessage) > 240) {
        $safeMessage = mb_substr($safeMessage, 0, 237) . '…';
    }

    try {
        $app->get('logger')->anomaly('http.500', [
            'error_code' => $errorCode,
            'message' => $rawMessage,
            'type' => $errorType,
            'file' => $e->getFile(),
            'line' => $errorLine,
            'request' => $errorRequest,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_id' => $_SESSION['user']['id'] ?? null,
            'trace' => $e->getTraceAsString(),
        ]);
        $app->get('logger')->error($rawMessage, [
            'error_code' => $errorCode,
            'type' => $errorType,
            'file' => $e->getFile(),
            'line' => $errorLine,
            'request' => $errorRequest,
        ]);
    } catch (Throwable) {
        // ignore logger failures
    }

    http_response_code(500);
    if ($debug) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $errorCode . "\n" . $e->getMessage() . "\n" . $e->getTraceAsString();
        return;
    }

    $payload = [
        'title' => __('errors.500'),
        'errorCode' => $errorCode,
        'errorType' => $errorTypeShort,
        'errorFile' => $errorFile,
        'errorLine' => $errorLine,
        'errorTime' => $errorTime,
        'errorRequest' => $errorRequest,
        'errorMessage' => $safeMessage,
    ];

    try {
        echo $app->get(\Socly\Core\View::class)->render('errors/500', $payload, 'layouts/guest');
        return;
    } catch (Throwable) {
        // Fallback if layout/view rendering also fails.
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title></head><body>';
    echo '<h1>' . htmlspecialchars(__('errors.500'), ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p>' . htmlspecialchars(__('errors.500_text'), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><strong>' . htmlspecialchars(__('errors.500_code'), ENT_QUOTES, 'UTF-8') . ':</strong> ';
    echo '<code>' . htmlspecialchars($errorCode, ENT_QUOTES, 'UTF-8') . '</code></p>';
    echo '<p><code>' . htmlspecialchars($errorTypeShort . ' @ ' . $errorFile . ':' . $errorLine, ENT_QUOTES, 'UTF-8') . '</code></p>';
    echo '<p><code>' . htmlspecialchars($errorRequest, ENT_QUOTES, 'UTF-8') . '</code></p>';
    if ($safeMessage !== '') {
        echo '<p><code>' . htmlspecialchars($safeMessage, ENT_QUOTES, 'UTF-8') . '</code></p>';
    }
    echo '</body></html>';
});

set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($app): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    try {
        $app->get('logger')->anomaly('php.error', [
            'message' => $message,
            'severity' => $severity,
            'file' => $file,
            'line' => $line,
        ]);
        $app->get('logger')->error($message, ['severity' => $severity, 'file' => $file, 'line' => $line]);
    } catch (Throwable) {
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$router = $app->get('router');
require dirname(__DIR__) . '/bootstrap/routes.php';

$app->run();
