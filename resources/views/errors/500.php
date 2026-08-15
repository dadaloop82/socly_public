<?php

declare(strict_types=1);

/** @var string|null $errorCode */
/** @var string|null $errorType */
/** @var string|null $errorFile */
/** @var int|null $errorLine */
/** @var string|null $errorTime */
/** @var string|null $errorRequest */
/** @var string|null $errorMessage */

$errorCode = (string) ($errorCode ?? 'SOC-UNKNOWN');
$errorType = (string) ($errorType ?? 'RuntimeError');
$errorFile = (string) ($errorFile ?? '—');
$errorLine = (int) ($errorLine ?? 0);
$errorTime = (string) ($errorTime ?? date('c'));
$errorRequest = (string) ($errorRequest ?? '—');
$errorMessage = trim((string) ($errorMessage ?? ''));
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title h1"><?= e(__('errors.500')) ?></h1>
        <p class="page-lede"><?= e(__('errors.500_text')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/')) ?>"><?= e(__('common.back')) ?></a>
    </div>
</div>

<section class="form-card error-tech-card">
    <h2 class="h3"><?= e(__('errors.500_tech_title')) ?></h2>
    <p class="muted"><?= e(__('errors.500_tech_hint')) ?></p>
    <dl class="error-tech-list">
        <div>
            <dt><?= e(__('errors.500_code')) ?></dt>
            <dd><code class="error-code"><?= e($errorCode) ?></code></dd>
        </div>
        <div>
            <dt><?= e(__('errors.500_time')) ?></dt>
            <dd><?= e($errorTime) ?></dd>
        </div>
        <div>
            <dt><?= e(__('errors.500_type')) ?></dt>
            <dd><code><?= e($errorType) ?></code></dd>
        </div>
        <div>
            <dt><?= e(__('errors.500_location')) ?></dt>
            <dd><code><?= e($errorFile) ?>:<?= e((string) $errorLine) ?></code></dd>
        </div>
        <div>
            <dt><?= e(__('errors.500_request')) ?></dt>
            <dd><code><?= e($errorRequest) ?></code></dd>
        </div>
        <?php if ($errorMessage !== ''): ?>
            <div class="error-tech-message">
                <dt><?= e(__('errors.500_message')) ?></dt>
                <dd><code><?= e($errorMessage) ?></code></dd>
            </div>
        <?php endif; ?>
    </dl>
</section>
