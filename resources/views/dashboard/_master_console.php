<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */

/**
 * Maintenance console for the platform SuperAdmin (dashboard).
 *
 * Rendered only when DashboardController detected an active maintenance session;
 * every value is printed escaped.
 *
 * @var array{
 *   account:array{id:int,name:string,email:string,session_user_id:int},
 *   current:array{at:string,ip:string}|null,
 *   previous:array{at:string,ip:string}|null,
 *   recent_users:list<array{id:int,name:string,email:string,at:string,ip:string}>,
 *   problems:list<array{at:string,level:string,message:string}>
 * } $console
 */
$account = $console['account'];
$current = $console['current'];
$previous = $console['previous'];
$recentUsers = is_array($console['recent_users'] ?? null) ? $console['recent_users'] : [];
$problems = $console['problems'];
$levelClass = static fn (string $level): string => match (strtoupper($level)) {
    'ERROR' => 'master-console-log-level--error',
    'WARNING' => 'master-console-log-level--warning',
    default => 'master-console-log-level--anomaly',
};
?>
<section class="panel master-console" aria-labelledby="master-console-title">
    <div class="master-console-head">
        <h2 class="master-console-title" id="master-console-title"><?= e(__('master_console.title')) ?></h2>
        <p class="master-console-subtitle"><?= e(__('master_console.subtitle')) ?></p>
    </div>

    <dl class="master-console-meta">
        <dt><?= e(__('master_console.connected_label')) ?></dt>
        <dd><?= e(__('master_console.connected_value', ['name' => $account['name'], 'email' => $account['email']])) ?></dd>

        <dt><?= e(__('master_console.session_label')) ?></dt>
        <dd>
            <?php if ($current !== null): ?>
                <?= e(__('master_console.session_value', [
                    'at' => format_datetime($current['at']),
                    'ip' => $current['ip'] !== '' ? $current['ip'] : '—',
                ])) ?>
            <?php else: ?>
                <?= e(__('master_console.session_unknown')) ?>
            <?php endif; ?>
        </dd>

        <dt><?= e(__('master_console.last_label')) ?></dt>
        <dd>
            <?php if ($previous !== null): ?>
                <?= e(__('master_console.last_value', [
                    'at' => format_datetime($previous['at']),
                    'ip' => $previous['ip'] !== '' ? $previous['ip'] : '—',
                ])) ?>
            <?php else: ?>
                <?= e(__('master_console.last_never')) ?>
            <?php endif; ?>
        </dd>
    </dl>

    <h3 class="master-console-logs-title"><?= e(__('master_console.recent_users_title')) ?></h3>
    <?php if ($recentUsers === []): ?>
        <p class="master-console-empty"><?= e(__('master_console.recent_users_empty')) ?></p>
    <?php else: ?>
        <ul class="master-console-users">
            <?php foreach ($recentUsers as $recent): ?>
                <li class="master-console-user">
                    <span class="master-console-user-time"><?= e(format_datetime($recent['at'])) ?></span>
                    <span class="master-console-user-name"><?= e($recent['name']) ?></span>
                    <span class="master-console-user-email"><?= e($recent['email']) ?></span>
                    <span class="master-console-user-ip"><?= e($recent['ip'] !== '' ? $recent['ip'] : '—') ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h3 class="master-console-logs-title"><?= e(__('master_console.logs_title')) ?></h3>
    <?php if ($problems === []): ?>
        <p class="master-console-empty"><?= e(__('master_console.logs_empty')) ?></p>
    <?php else: ?>
        <ul class="master-console-logs">
            <?php foreach ($problems as $problem): ?>
                <li class="master-console-log">
                    <span class="master-console-log-time"><?= e(format_datetime($problem['at'])) ?></span>
                    <span class="master-console-log-level <?= e($levelClass($problem['level'])) ?>"><?= e($problem['level']) ?></span>
                    <span class="master-console-log-text"><?= e($problem['message']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
