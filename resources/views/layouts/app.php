<?php
/** @var string $content */
$branding = app()->branding();
$user = auth_user();
$assocName = (string) ($branding['name'] ?? 'SOCLY');
$hasAssoc = $assocName !== '' && strcasecmp($assocName, 'SOCLY') !== 0;
$uri = $_SERVER['REQUEST_URI'] ?? '';
$pluginMenu = [];
$componentMenu = [];
try {
    if (app()->isInstalled()) {
        $pluginMenu = app('plugins')->menuItems();
        $componentMenu = app(\Socly\Services\ComponentService::class)->menuItems();
    }
} catch (\Throwable) {
}
$navActive = static function (string $needle) use ($uri): string {
    return str_contains($uri, $needle) ? 'active' : '';
};
$isAdminConfig = $user && can('settings.manage');
$configActive = str_contains($uri, '/settings') || str_contains($uri, '/users') || str_contains($uri, '/plugins');
?>
<!DOCTYPE html>
<html lang="<?= e(app('translator')->getLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <?= view_partial('partials/password_i18n_meta') ?>
    <meta name="session-ping-url" content="<?= e(url('/session/ping')) ?>">
    <meta name="login-url" content="<?= e(url('/login')) ?>">
    <title><?= e(($title ?? 'SOCLY') . ' · ' . $assocName) ?></title>
    <link rel="icon" href="<?= e(socly_icon_url()) ?>" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,560;9..144,700&family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>?v=<?= e((string) (@filemtime(code_path('public/assets/css/app.css')) ?: time())) ?>">
    <style>:root {
      <?= brand_root_style_decls($branding['primary'] ?? null, $branding['accent'] ?? null) ?>
    }</style>
</head>
<body
    data-cities-url="<?= e(url('/api/geo/cities')) ?>"
    data-addresses-url="<?= e(url('/api/geo/addresses')) ?>"
    data-msg-city-first="<?= e(__('members.address_city_first')) ?>"
>
<?php
$temporaryInstance = false;
$temporaryExpiresLabel = '';
try {
    if (app()->isInstalled()) {
        $settings = app(\Socly\Services\SettingsService::class);
        $temporaryInstance = (string) $settings->get('app.temporary_instance', '0') === '1';
        if ($temporaryInstance) {
            $exp = (string) $settings->get('app.instance_expires_at', '');
            if ($exp !== '') {
                $ts = strtotime($exp);
                if ($ts !== false) {
                    $temporaryExpiresLabel = date('d/m/Y H:i', $ts);
                }
            }
        }
    }
} catch (\Throwable) {
}
?>
<?php if ($temporaryInstance): ?>
<div style="background:#fff3d6;color:#6b4a00;padding:0.55rem 1rem;text-align:center;font-size:0.9rem;font-weight:700;border-bottom:1px solid #f0d48a">
  Ambiente di prova<?= $temporaryExpiresLabel !== '' ? ' — scade il ' . e($temporaryExpiresLabel) : '' ?>. Configurazione e aggiornamenti non disponibili.
</div>
<?php endif; ?>
<?php if ($user): ?>
<div class="app-shell" data-app-shell>
    <div class="sidebar-backdrop" data-sidebar-close></div>
    <aside class="sidebar" id="app-sidebar" data-sidebar aria-label="<?= e(__('nav.menu')) ?>">
        <div class="sidebar-brand<?= assoc_logo_url() ? ' has-assoc-logo' : '' ?>">
            <button type="button" class="sidebar-close" data-sidebar-close aria-label="<?= e(__('nav.close_menu')) ?>">×</button>
            <div class="sidebar-brand-stack">
                <?= socly_mark_img('sidebar-mark', 'SOCLY', 'light') ?>
                <?php if ($hasAssoc): ?>
                    <p class="sidebar-for">
                        <span class="per"><?= e(__('auth.for')) ?></span>
                        <?= assoc_lockup_html(['class' => 'assoc-lockup-sidebar']) ?>
                    </p>
                <?php endif; ?>
                <?= assoc_logo_img('assoc-logo sidebar-assoc-logo') ?>
            </div>
        </div>

        <nav class="nav">
            <span class="nav-label"><?= e(__('nav.menu')) ?></span>
            <?php if (can('dashboard.view')): ?>
                <a href="<?= e(url('/dashboard')) ?>" class="<?= e($navActive('/dashboard')) ?>"><?= e(__('nav.dashboard')) ?></a>
            <?php endif; ?>
            <?php foreach ($componentMenu as $item): ?>
                <?php if (!empty($item['permission']) && !can($item['permission'])) continue; ?>
                <a href="<?= e(url($item['path'])) ?>" class="<?= e($navActive($item['path'])) ?>"><?= e(__($item['label'])) ?></a>
            <?php endforeach; ?>
            <?php foreach ($pluginMenu as $item): ?>
                <?php if (!empty($item['permission']) && !can($item['permission'])) continue; ?>
                <a href="<?= e(url($item['path'])) ?>"><?= e(__($item['label'])) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php
        $sidebarDeadlines = [];
        try {
            if (
                app()->isInstalled()
                && function_exists('component_enabled')
                && component_enabled('deadlines')
                && can('deadlines.view')
            ) {
                $sidebarDeadlines = app(\Socly\Services\DeadlineService::class)->upcoming(12);
            }
        } catch (\Throwable) {
            $sidebarDeadlines = [];
        }
        $sidebarToday = date('Y-m-d');
        $sidebarSoon = date('Y-m-d', strtotime('+30 days'));
        ?>
        <?php if ($sidebarDeadlines !== []): ?>
            <section
                class="sidebar-deadlines"
                data-sidebar-deadlines
                data-more-template="<?= e(__('deadlines.sidebar_more')) ?>"
                aria-label="<?= e(__('nav.upcoming_deadlines')) ?>"
            >
                <div class="sidebar-deadlines-head">
                    <h2 class="sidebar-deadlines-title"><?= e(__('nav.upcoming_deadlines')) ?></h2>
                    <a class="sidebar-deadlines-link" href="<?= e(url('/deadlines')) ?>"><?= e(__('nav.all_deadlines')) ?></a>
                </div>
                <ul class="sidebar-deadlines-list" data-sidebar-deadlines-list>
                    <?php foreach ($sidebarDeadlines as $item): ?>
                        <?php
                        $due = (string) ($item['due_date'] ?? '');
                        $state = 'valid';
                        if ($due !== '' && $due < $sidebarToday) {
                            $state = 'overdue';
                        } elseif ($due !== '' && $due <= $sidebarSoon) {
                            $state = 'soon';
                        }
                        $itemId = (int) ($item['id'] ?? 0);
                        $href = can('deadlines.manage')
                            ? url('/deadlines/' . $itemId . '/edit')
                            : url('/deadlines');
                        ?>
                        <li class="sidebar-deadline-item is-<?= e($state) ?>" data-sidebar-deadline-item>
                            <a href="<?= e($href) ?>" class="sidebar-deadline-link">
                                <span class="sidebar-deadline-title"><?= e((string) ($item['title'] ?? '')) ?></span>
                                <span class="sidebar-deadline-meta">
                                    <span class="sidebar-deadline-badge"><?= e(__('deadlines.badge_' . $state)) ?></span>
                                    <time datetime="<?= e($due) ?>"><?= e(format_date($due) ?: '—') ?></time>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a
                    class="sidebar-deadlines-more"
                    href="<?= e(url('/deadlines')) ?>"
                    data-sidebar-deadlines-more
                    hidden
                ></a>
            </section>
        <?php endif; ?>

        <div class="sidebar-footer">
            <div class="user-name"><?= e($user['name'] ?? '') ?></div>
            <div class="user-meta"><?= e($user['email'] ?? '') ?></div>
            <form method="post" action="<?= e(url('/logout')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-ghost btn-sm btn-logout" type="submit"><?= e(__('nav.logout')) ?></button>
            </form>
        </div>
    </aside>

    <div class="main-wrap">
        <header class="topbar" data-topbar>
            <button type="button" class="topbar-menu" data-sidebar-open aria-controls="app-sidebar" aria-expanded="false" aria-label="<?= e(__('nav.open_menu')) ?>">
                <span class="topbar-menu-bars" aria-hidden="true"></span>
            </button>
            <div class="topbar-brand">
                <?= socly_mark_img('topbar-mark') ?>
                <?php if ($hasAssoc): ?>
                    <?= assoc_lockup_html(['class' => 'assoc-lockup-topbar']) ?>
                <?php endif; ?>
            </div>
            <div class="topbar-spacer" aria-hidden="true"></div>
            <?php if ($isAdminConfig): ?>
                <a
                    class="topbar-config<?= $configActive ? ' is-active' : '' ?>"
                    href="<?= e(url('/settings')) ?>"
                    title="<?= e(__('nav.settings')) ?>"
                    aria-label="<?= e(__('nav.open_settings')) ?>"
                    data-topbar-config
                >
                    <span class="topbar-config-icon" aria-hidden="true"></span>
                </a>
            <?php endif; ?>
        </header>
        <main class="main">
            <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e(is_string($msg) ? $msg : '') ?></div><?php endif; ?>
            <?php if ($errors = flash('errors')): ?>
                <div class="alert alert-error">
                    <?php if (is_array($errors)): foreach ($errors as $err): ?>
                        <div><?= e(is_array($err) ? implode(', ', $err) : (string) $err) ?></div>
                    <?php endforeach; else: ?>
                        <div><?= e((string)$errors) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php
            $updateInfo = null;
            if (can('settings.manage')) {
                try {
                    $updateInfo = app(\Socly\Services\UpdateService::class)->check();
                } catch (\Throwable) {
                    $updateInfo = null;
                }
            }
            ?>
            <?php if (!empty($updateInfo['available'])): ?>
                <div class="update-banner">
                    <div>
                        <strong><?= e(__('updates.available_title')) ?></strong>
                        <span><?= e(__('updates.available_text', [
                            'current' => (string) ($updateInfo['current'] ?? ''),
                            'remote' => (string) ($updateInfo['remote'] ?? ''),
                        ])) ?></span>
                    </div>
                    <form method="post" action="<?= e(url('/updates/install')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm" type="submit"><?= e(__('updates.install')) ?></button>
                    </form>
                </div>
            <?php endif; ?>
            <?= $content ?>
        </main>
        <footer class="app-footer">
            <div class="app-footer-brand">
                <?= socly_word_html('socly-word-footer') ?>
                <?php if ($hasAssoc): ?>
                    <span class="footer-for">· <?= e(__('auth.for')) ?> <?= assoc_lockup_html(['class' => 'assoc-lockup-footer']) ?></span>
                <?php endif; ?>
                <span class="footer-meta">· © <?= e(date('Y')) ?> · v<?= e(app_version()) ?></span>
            </div>
            <div><?= credit_line() ?></div>
        </footer>
    </div>
</div>
<?php else: ?>
<main class="main"><?= $content ?></main>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>?v=<?= e((string) (@filemtime(base_path('public/assets/js/app.js')) ?: time())) ?>"></script>
</body>
</html>
