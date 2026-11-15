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

/**
 * Model D (RUNTS): choose the exercise to generate, from the years available in the cash book.
 *
 * @var list<array{year:int,approved:int,total:int}> $years
 * @var int $currentYear
 */
$years = is_array($years ?? null) ? $years : [];
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('runts.choose_title')) ?></h1>
        <p class="page-lede"><?= e(__('runts.choose_lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/budgets')) ?>"><?= e(__('runts.back')) ?></a>
        <a class="btn btn-ghost" href="<?= e(url('/treasury')) ?>"><?= e(__('treasury.title')) ?></a>
    </div>
</div>

<?php if ($years === []): ?>
    <div class="panel">
        <div class="empty-state">
            <strong><?= e(__('runts.choose_none')) ?></strong>
            <p><?= e(__('runts.choose_none_hint')) ?></p>
            <a class="btn btn-sm" href="<?= e(url('/treasury')) ?>"><?= e(__('runts.choose_none_action')) ?></a>
        </div>
    </div>
<?php else: ?>
    <section class="panel runts-year-panel">
        <header class="panel-header">
            <div>
                <h2 class="section-title"><?= e(__('runts.choose_available')) ?></h2>
                <p class="section-lede"><?= e(__('runts.choose_available_lede')) ?></p>
            </div>
        </header>
        <div class="runts-year-grid">
            <?php foreach ($years as $row): ?>
                <?php
                $rowYear = (int) ($row['year'] ?? 0);
                $approved = (int) ($row['approved'] ?? 0);
                $total = (int) ($row['total'] ?? 0);
                ?>
                <a class="runts-year-card<?= $rowYear === (int) $currentYear ? ' is-current' : '' ?><?= $approved === 0 ? ' is-pending' : '' ?>"
                    href="<?= e(url('/rendiconto/' . $rowYear)) ?>">
                    <span class="runts-year-value"><?= $rowYear ?></span>
                    <span class="runts-year-meta"><?= e(__('runts.year_movements', [
                        'approved' => (string) $approved,
                        'total' => (string) $total,
                    ])) ?></span>
                    <?php if ($approved === 0): ?>
                        <span class="badge badge-warn"><?= e(__('runts.year_to_approve')) ?></span>
                    <?php elseif ($rowYear === (int) $currentYear): ?>
                        <span class="badge badge-ok"><?= e(__('runts.year_current')) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
