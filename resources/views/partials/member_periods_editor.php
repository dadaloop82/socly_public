<?php
/**
 * Membership period editor.
 *
 * @var list<array<string,mixed>> $periods
 */
$periodRows = is_array($periods ?? null) ? $periods : [];
$year = (int) date('Y');
$hasCurrentYear = false;
foreach ($periodRows as $row) {
    $label = (string) ($row['label'] ?? '');
    $starts = (string) ($row['starts_on'] ?? '');
    if (str_starts_with($starts, (string) $year) || $label === (string) $year) {
        $hasCurrentYear = true;
        break;
    }
    $ends = (string) ($row['ends_on'] ?? '');
    $pivot = sprintf('%d-01-01', $year);
    if ($starts !== '' && $ends !== '' && $starts <= $pivot && $ends >= $pivot) {
        $hasCurrentYear = true;
        break;
    }
}
$needsCurrentYear = $periodRows !== [] && !$hasCurrentYear;
$prefill = $periodRows === [] || $needsCurrentYear;
$newDefaults = [
    'starts_on' => $prefill ? sprintf('%d-01-01', $year) : '',
    'ends_on' => $prefill ? sprintf('%d-12-31', $year) : '',
    'is_current' => $prefill,
];
$requireNew = $periodRows === [] || $needsCurrentYear;
?>
<div class="setup-membership" data-setup-membership-periods>
    <p class="setup-hint muted"><?= e(__('settings.periods_auto_hint')) ?></p>
    <?php if ($needsCurrentYear): ?>
        <p class="setup-hint setup-hint-warn"><?= e(__('setup.periods_need_current_year')) ?></p>
    <?php endif; ?>
    <?php if ($periodRows !== []): ?>
        <div class="setup-membership-list">
            <?php foreach ($periodRows as $period): ?>
                <?php $pid = (int) ($period['id'] ?? 0); ?>
                <div class="setup-membership-card">
                    <p class="setup-period-label muted"><?= e((string) ($period['label'] ?? '')) ?></p>
                    <div class="setup-equal-row">
                        <label class="setup-field">
                            <span><?= e(__('install.starts_on')) ?> *</span>
                            <input type="date" name="periods[<?= $pid ?>][starts_on]" value="<?= e((string) ($period['starts_on'] ?? '')) ?>" required data-period-start>
                        </label>
                        <label class="setup-field">
                            <span><?= e(__('install.ends_on')) ?> *</span>
                            <input type="date" name="periods[<?= $pid ?>][ends_on]" value="<?= e((string) ($period['ends_on'] ?? '')) ?>" required data-period-end>
                        </label>
                    </div>
                    <label class="setup-check setup-check-prominent">
                        <input type="checkbox" name="periods[<?= $pid ?>][is_current]" value="1" <?= !empty($period['is_current']) ? 'checked' : '' ?>>
                        <span><?= e(__('settings.is_current')) ?></span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($periodRows !== []): ?>
        <h3 class="setup-subhead"><?= e($needsCurrentYear ? __('setup.periods_add_year') : __('settings.add_period')) ?></h3>
    <?php endif; ?>
    <div class="setup-membership-card setup-membership-card-new">
        <div class="setup-equal-row">
            <label class="setup-field">
                <span><?= e(__('install.starts_on')) ?><?= $requireNew ? ' *' : '' ?></span>
                <input type="date" name="starts_on" value="<?= e((string) $newDefaults['starts_on']) ?>" <?= $requireNew ? 'required' : '' ?> data-period-start>
            </label>
            <label class="setup-field">
                <span><?= e(__('install.ends_on')) ?><?= $requireNew ? ' *' : '' ?></span>
                <input type="date" name="ends_on" value="<?= e((string) $newDefaults['ends_on']) ?>" <?= $requireNew ? 'required' : '' ?> data-period-end>
            </label>
        </div>
        <label class="setup-check setup-check-prominent">
            <input type="checkbox" name="is_current" value="1" <?= !empty($newDefaults['is_current']) ? 'checked' : '' ?>>
            <span><?= e(__('settings.is_current')) ?></span>
        </label>
    </div>
</div>
