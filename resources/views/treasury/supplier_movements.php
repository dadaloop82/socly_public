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
 * Movements of one counterparty, rendered with `layouts/embed` inside the dialog opened from
 * “Gestione controparti”. Read-only: editing stays on the movement card, opened in the parent window.
 *
 * @var string $beneficiary
 * @var list<array<string,mixed>> $movements
 * @var int $total
 * @var \Socly\Services\CurrencyService $currency
 * @var string $ledger_url
 */
$beneficiary = (string) ($beneficiary ?? '');
$movements = is_array($movements ?? null) ? $movements : [];
$total = (int) ($total ?? 0);
$ledgerUrl = (string) ($ledger_url ?? url('/treasury'));
$canManage = can('treasury.manage');
?>
<div class="supplier-movements-page">
    <header class="supplier-movements-head">
        <div>
            <h1 class="supplier-movements-name"><?= e($beneficiary) ?></h1>
            <p class="muted"><?= e(__('treasury.supplier_movements_count', ['count' => (string) $total])) ?></p>
        </div>
        <a class="btn btn-sm btn-ghost" href="<?= e($ledgerUrl) ?>" target="_top"><?= e(__('treasury.supplier_movements_open')) ?></a>
    </header>

    <?php if ($movements === []): ?>
        <p class="empty-state"><?= e(__('treasury.supplier_movements_none')) ?></p>
    <?php else: ?>
        <div class="table-wrap embedded">
            <table class="data-table supplier-movements-table">
                <thead>
                <tr>
                    <th><?= e(__('treasury.date')) ?></th>
                    <th><?= e(__('treasury.description')) ?></th>
                    <th><?= e(__('treasury.category')) ?></th>
                    <th class="is-num"><?= e(__('treasury.amount')) ?></th>
                    <?php if ($canManage): ?>
                        <th><span class="visually-hidden"><?= e(__('treasury.actions')) ?></span></th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($movements as $movement): ?>
                    <?php
                    $movementId = (int) ($movement['id'] ?? 0);
                    $direction = (string) ($movement['direction'] ?? 'expense');
                    $amount = (float) ($movement['amount'] ?? 0);
                    $description = trim((string) ($movement['description'] ?? ''));
                    if ($description === '') {
                        $description = trim((string) ($movement['invoice_title'] ?? ''));
                    }
                    $accountKey = \Socly\Services\TreasuryService::normalizeAccount((string) ($movement['account'] ?? ''));
                    $accountLabel = $accountKey !== '' ? treasury_account_label($accountKey) : '';
                    $categoryKey = trim((string) ($movement['category'] ?? ''));
                    $categoryLabel = $categoryKey !== '' ? treasury_category_label($categoryKey) : '';
                    $sign = $direction === 'income' ? '+' : ($direction === 'transfer' ? '↔ ' : '−');
                    ?>
                    <tr>
                        <td class="supplier-movements-date"><?= e(format_date($movement['movement_date'] ?? null) ?: (string) ($movement['movement_date'] ?? '')) ?></td>
                        <td>
                            <span class="supplier-movements-description"><?= e($description !== '' ? $description : (string) __('treasury.none')) ?></span>
                            <?php if ($accountLabel !== ''): ?>
                                <span class="muted supplier-movements-meta"><?= e($accountLabel) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($categoryLabel !== '' ? $categoryLabel : (string) __('treasury.none')) ?></td>
                        <td class="is-num <?= $direction === 'income' ? 'is-income' : ($direction === 'transfer' ? '' : 'is-expense') ?>">
                            <?= e($sign . $currency->format($amount)) ?>
                        </td>
                        <?php if ($canManage): ?>
                            <td class="supplier-movements-actions">
                                <?php if ($movementId > 0): ?>
                                    <a class="btn btn-sm btn-ghost" href="<?= e(url('/treasury/' . $movementId . '/edit')) ?>" target="_top"><?= e(__('treasury.edit')) ?></a>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($movements) < $total): ?>
            <p class="muted supplier-movements-more">
                <?= e(__('treasury.supplier_movements_limited', [
                    'shown' => (string) count($movements),
                    'total' => (string) $total,
                ])) ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>
