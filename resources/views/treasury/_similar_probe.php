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
 * “I noticed N similar movements”: proposal shown both on the ledger (after a save that returned
 * to the list) and on the movement card (after “Update”, which now keeps the operator on the card).
 * The rows are listed with date, counterparty, wallet and amount (plus what is still empty) so the
 * batch can be judged before applying anything.
 *
 * @var array<string,mixed> $probe
 * @var string $ret_path     ledger state to come back to after applying
 * @var string $back_path    card to come back to ('' when the proposal lives in the ledger)
 * @var string $dismiss_url  where “Ignore” goes
 */
$probe = is_array($probe ?? null) ? $probe : null;
if ($probe === null) {
    return;
}
$ret_path = (string) ($ret_path ?? '/treasury');
$back_path = (string) ($back_path ?? '');
$dismiss_url = (string) ($dismiss_url ?? url($ret_path));
$total = (int) ($probe['total'] ?? 0);
$matches = is_array($probe['matches'] ?? null) ? $probe['matches'] : [];
$fieldLabels = [
    'category' => (string) __('treasury.category'),
    'runts_line' => (string) __('runts.line_label'),
    'budget_item_id' => (string) __('treasury.budget_item'),
];
$appliedLabels = [];
foreach ((array) ($probe['fields'] ?? []) as $probeField) {
    if (isset($fieldLabels[(string) $probeField])) {
        $appliedLabels[] = $fieldLabels[(string) $probeField];
    }
}
$range = '';
if ((string) ($probe['date_from_formatted'] ?? '') !== '' && (string) ($probe['date_to_formatted'] ?? '') !== '') {
    $range = (string) $probe['date_from_formatted'] === (string) $probe['date_to_formatted']
        ? (string) $probe['date_from_formatted']
        : (string) $probe['date_from_formatted'] . ' – ' . (string) $probe['date_to_formatted'];
}
$openUrl = static function (int $id) use ($ret_path): string {
    return url('/treasury/' . $id . '/edit') . '?' . http_build_query(['ret' => $ret_path]);
};
?>
<section class="panel tmf-similar-probe" data-similar-probe role="alert" aria-live="polite" id="similar-probe">
    <strong class="tmf-similar-title">
        <?= e(__('treasury.similar_title', ['count' => (string) (int) ($probe['total'] ?? 0)])) ?>
    </strong>
    <p class="tmf-similar-hint"><?= e(__('treasury.similar_hint')) ?></p>
    <?php if ($matches !== []): ?>
        <p class="tmf-similar-summary">
            <span><?= e(__('treasury.similar_summary', ['count' => (string) $total, 'range' => $range])) ?></span>
            <?php if ((string) ($probe['amount_total_formatted'] ?? '') !== ''): ?>
                <span class="tmf-similar-total"><?= e(__('treasury.similar_total', [
                    'amount' => (string) $probe['amount_total_formatted'],
                ])) ?></span>
            <?php endif; ?>
            <?php if ($appliedLabels !== []): ?>
                <span><?= e(__('treasury.similar_fields', ['fields' => implode(' · ', $appliedLabels)])) ?></span>
            <?php endif; ?>
        </p>
        <div class="tmf-similar-table-wrap">
            <table class="tmf-similar-table">
                <thead>
                <tr>
                    <th scope="col"><?= e(__('treasury.operation_date')) ?></th>
                    <th scope="col"><?= e(__('treasury.description')) ?></th>
                    <th scope="col"><?= e(__('treasury.counterparty_short')) ?></th>
                    <th scope="col"><?= e(__('treasury.account_short')) ?></th>
                    <th scope="col" class="is-amount"><?= e(__('treasury.amount')) ?></th>
                    <th scope="col"><span class="visually-hidden"><?= e(__('treasury.actions')) ?></span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($matches as $match): ?>
                    <?php
                    $matchId = (int) ($match['id'] ?? 0);
                    $missing = [];
                    foreach ((array) ($match['missing'] ?? []) as $missingField) {
                        if (isset($fieldLabels[(string) $missingField])) {
                            $missing[] = $fieldLabels[(string) $missingField];
                        }
                    }
                    ?>
                    <tr>
                        <td class="tmf-similar-date"><?= e((string) ($match['date_formatted'] ?? '')) ?></td>
                        <td class="tmf-similar-text">
                            <?= e((string) ($match['description'] ?? '')) ?>
                            <?php if ($missing !== [] && count($missing) < count($appliedLabels)): ?>
                                <span class="tmf-similar-missing"><?= e(__('treasury.similar_missing', [
                                    'fields' => implode(' · ', $missing),
                                ])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) ($match['counterparty'] ?? '')) ?></td>
                        <td><?= e((string) ($match['account_label'] ?? '')) ?></td>
                        <td class="tmf-similar-amount"><?= e((string) ($match['amount_formatted'] ?? '')) ?></td>
                        <td class="tmf-similar-open-cell">
                            <?php if ($matchId > 0): ?>
                                <a class="tmf-similar-open" href="<?= e($openUrl($matchId)) ?>"
                                   target="_blank" rel="noopener"
                                   title="<?= e(__('treasury.similar_open')) ?>"
                                   aria-label="<?= e(__('treasury.similar_open')) ?>">↗</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total > count($matches)): ?>
            <p class="tmf-similar-skipped">
                <?= e(__('treasury.similar_more', ['count' => (string) ($total - count($matches))])) ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ((int) ($probe['skipped'] ?? 0) > 0): ?>
        <p class="tmf-similar-skipped">
            <?= e(__('treasury.similar_skipped', ['count' => (string) (int) $probe['skipped']])) ?>
        </p>
    <?php endif; ?>
    <p class="tmf-similar-changes">
        <?= e(__('treasury.similar_changes')) ?>:
        <?php
        $changeParts = [];
        foreach (($probe['changes'] ?? []) as $change) {
            $changeParts[] = $change['label'] . ': ' . $change['value'];
        }
        echo e(implode(' · ', $changeParts));
        ?>
    </p>
    <form method="post" action="<?= e(url('/treasury/similar/apply-form')) ?>" class="tmf-similar-actions">
        <?= csrf_field() ?>
        <?= field('hidden', ['name' => 'movement_id', 'value' => (string) (int) ($probe['movement']['id'] ?? 0)]) ?>
        <?php foreach (($probe['ids'] ?? []) as $probeId): ?>
            <?= field('hidden', ['name' => 'ids[]', 'value' => (string) (int) $probeId]) ?>
        <?php endforeach; ?>
        <?= field('hidden', ['name' => 'category', 'value' => (string) ($probe['movement']['category'] ?? '')]) ?>
        <?= field('hidden', ['name' => 'runts_line', 'value' => (string) ($probe['movement']['runts_line'] ?? '')]) ?>
        <?= field('hidden', ['name' => 'budget_item_id', 'value' => (string) (int) ($probe['movement']['budget_item_id'] ?? 0)]) ?>
        <?= field('hidden', ['name' => 'ret', 'value' => $ret_path]) ?>
        <?php if ($back_path !== ''): ?>
            <?= field('hidden', ['name' => 'back', 'value' => $back_path]) ?>
        <?php endif; ?>
        <button class="btn btn-sm" type="submit">
            <?= e(__('treasury.similar_apply', ['count' => (string) (int) ($probe['total'] ?? 0)])) ?>
        </button>
        <a class="btn btn-ghost btn-sm" href="<?= e($dismiss_url) ?>"><?= e(__('treasury.similar_dismiss')) ?></a>
    </form>
</section>
