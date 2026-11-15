<?php
/*
 * Socly Project Header
 *
 *   ███████╗ ██████╗  ██████╗ ██╗   ██╗
 *   ██╔════╝██╔═══██╗██╔════╝ ██║   ██║
 *   ███████╗██║   ██║██║      ██║   ██║
 *   ╚════██║██║   ██║██║      ██║   ██║
 *   ███████║╚██████╔╝╚██████╗ ╚██████╔╝
 *   ╚══════╝ ╚═════╝  ╚═════╝  ╚═════╝
 *
 *   Socly — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */
 $forgotMailReady = !empty($mailReady); ?>
<h1 class="h1"><?= e(__('auth.forgot_title')) ?></h1>

<?php if ($forgotMailReady): ?>
<p class="lede"><?= e(__('auth.forgot_text')) ?></p>
<form method="post" action="<?= e(url('/password/forgot')) ?>">
    <?= csrf_field() ?>
    <?= field('email', [
        'name' => 'email',
        'id' => 'email',
        'label' => (string) __('auth.email'),
        'value' => (string) old('email'),
        'required' => true,
        'autocomplete' => 'username',
        'input_class' => 'auth-field-control',
        'attrs' => ['autofocus' => true],
    ]) ?>
    <button class="btn btn-block" type="submit"><?= e(__('auth.forgot_submit')) ?></button>
</form>
<?php else: ?>
<div class="auth-links">
    <p class="auth-forgot-note" data-i18n="auth.forgot_unavailable"><?= e(__('auth.forgot_unavailable')) ?></p>
</div>
<?php endif; ?>
<p class="auth-links" style="margin-top:1.1rem;justify-content:center">
    <a href="<?= e(url('/login')) ?>"><?= e(__('auth.back_to_login')) ?></a>
</p>
