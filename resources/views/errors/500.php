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
$supportEmail = trim((string) __('errors.500_support_email'));
if ($supportEmail === '' || !str_contains($supportEmail, '@')) {
    $supportEmail = 'info@socly.it';
}
$mailHref = 'mailto:' . $supportEmail . '?subject=' . rawurlencode('SOCLY ' . $errorCode);
$showTech = (bool) (config('app.debug', false) || (string) (getenv('SOCLY_SHOW_ERRORS') ?: '') === '1');
?>
<div class="error-reassure">
    <h1 class="h1"><?= e(__('errors.500_title')) ?></h1>
    <p class="lede"><?= e(__('errors.500_text')) ?></p>

    <section class="error-reassure-block">
        <h2 class="h3"><?= e(__('errors.500_what_title')) ?></h2>
        <p><?= e(__('errors.500_what_text')) ?></p>
    </section>

    <section class="error-reassure-block">
        <h2 class="h3"><?= e(__('errors.500_contact_title')) ?></h2>
        <p>
            <?php
            $parts = explode('{{EMAIL}}', __('errors.500_contact_text', ['email' => '{{EMAIL}}']), 2);
            echo e((string) ($parts[0] ?? ''));
            ?>
            <a href="<?= e($mailHref) ?>"><?= e($supportEmail) ?></a>
            <?= e((string) ($parts[1] ?? '')) ?>
        </p>
    </section>

    <p class="error-reassure-ref">
        <?= e(__('errors.500_ref')) ?>
        <code class="error-code"><?= e($errorCode) ?></code>
    </p>
    <p class="muted error-reassure-hint"><?= e(__('errors.500_ref_hint')) ?></p>

    <div class="actions">
        <a class="btn" href="<?= e(url('/')) ?>"><?= e(__('errors.500_home')) ?></a>
        <a class="btn btn-ghost" href="javascript:location.reload()"><?= e(__('errors.500_retry')) ?></a>
        <a class="btn btn-ghost" href="<?= e($mailHref) ?>"><?= e($supportEmail) ?></a>
    </div>

    <?php if ($showTech): ?>
        <details class="error-tech-details">
            <summary><?= e(__('errors.500_tech_title')) ?></summary>
            <p class="muted"><?= e(__('errors.500_tech_hint')) ?></p>
            <dl class="error-tech-list">
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
        </details>
    <?php endif; ?>
</div>
