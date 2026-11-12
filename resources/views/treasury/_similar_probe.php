<?php
/**
 * “I noticed N similar movements”: proposal shown both on the ledger (after a save that returned
 * to the list) and on the movement card (after “Update”, which now keeps the operator on the card).
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
?>
<section class="panel tmf-similar-probe" data-similar-probe role="alert" aria-live="polite" id="similar-probe">
    <strong class="tmf-similar-title">
        <?= e(__('treasury.similar_title', ['count' => (string) (int) ($probe['total'] ?? 0)])) ?>
    </strong>
    <p class="tmf-similar-hint"><?= e(__('treasury.similar_hint')) ?></p>
    <ul class="tmf-similar-list">
        <?php foreach (($probe['matches'] ?? []) as $match): ?>
            <li>
                <span class="tmf-similar-date"><?= e((string) ($match['date_formatted'] ?? '')) ?></span>
                <span class="tmf-similar-text"><?= e((string) ($match['description'] ?? '')) ?></span>
                <span class="tmf-similar-amount"><?= e((string) ($match['amount_formatted'] ?? '')) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
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
