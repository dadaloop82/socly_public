<?php
/**
 * Bilancio consuntivo — elenco esercizi con il rendiconto per cassa (Modello D).
 *
 * @var list<array{year:int,income:float,can_generate:bool}> $years
 * @var float $limit
 * @var \Socly\Services\CurrencyService $currency
 */
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('runts.title')) ?></h1>
        <p class="page-lede"><?= e(__('runts.lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/budgets')) ?>"><?= e(__('nav.budget_preventivo')) ?></a>
    </div>
</div>

<p class="setup-hint muted"><?= e(__('runts.limit_hint', ['limit' => $currency->format((float) $limit)])) ?></p>

<?php if ($years === []): ?>
    <div class="panel">
        <div class="empty-state">
            <strong><?= e(__('runts.empty_title')) ?></strong>
            <p><?= e(__('runts.empty_text')) ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="panel">
        <div class="table-wrap embedded">
            <table class="data-table">
                <thead>
                <tr>
                    <th><?= e(__('runts.year')) ?></th>
                    <th><?= e(__('runts.income')) ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($years as $row): ?>
                    <tr>
                        <td><strong><?= e((string) (int) $row['year']) ?></strong></td>
                        <td><?= e($currency->format((float) $row['income'])) ?></td>
                        <td>
                            <?php if (!empty($row['can_generate'])): ?>
                                <a class="btn btn-sm" href="<?= e(url('/rendiconto/' . (int) $row['year'])) ?>">
                                    <?= e(__('runts.open')) ?>
                                </a>
                            <?php else: ?>
                                <span class="budget-badge is-draft"><?= e(__('runts.manual_title')) ?></span>
                                <span class="muted"><?= e(__('runts.manual_text', [
                                    'income' => $currency->format((float) $row['income']),
                                    'limit' => $currency->format((float) $limit),
                                ])) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
