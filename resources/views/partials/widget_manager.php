<?php
/**
 * Reusable per-user widget manager for a chart group.
 *
 * @var string $context
 * @var array<string,string> $widgets id => label
 * @var array{order?:list<string>,hidden?:list<string>,sizes?:array<string,int>} $layout
 */
$context = trim((string) ($context ?? ''));
$widgets = is_array($widgets ?? null) ? $widgets : [];
$layout = is_array($layout ?? null) ? $layout : [];
if ($context === '' || $widgets === []) {
    return;
}
$order = [];
foreach (($layout['order'] ?? []) as $id) {
    if (isset($widgets[$id]) && !in_array($id, $order, true)) {
        $order[] = $id;
    }
}
foreach ($widgets as $id => $label) {
    if (!in_array($id, $order, true)) {
        $order[] = $id;
    }
}
$hidden = array_flip(array_map('strval', $layout['hidden'] ?? []));
$sizes = is_array($layout['sizes'] ?? null) ? $layout['sizes'] : [];
$sizeOf = static function (string $id) use ($sizes): int {
    $value = (int) ($sizes[$id] ?? 1);
    return $value >= 1 && $value <= 3 ? $value : 1;
};
?>
<div class="widget-manager-row">
    <details class="widget-manager" data-widget-manager="<?= e($context) ?>"
        data-msg-drag="<?= e(__('widgets.drag')) ?>"
        data-msg-hide="<?= e(__('widgets.hide')) ?>"
        data-msg-up="<?= e(__('widgets.move_up')) ?>"
        data-msg-down="<?= e(__('widgets.move_down')) ?>"
        data-msg-width="<?= e(__('widgets.width')) ?>">
        <summary class="widget-manager-summary"><?= e(__('widgets.manage')) ?></summary>
        <div class="widget-manager-body">
            <p class="setup-hint muted"><?= e(__('widgets.hint')) ?></p>
            <ul class="widget-manager-list" data-widget-list>
                <?php foreach ($order as $id): ?>
                    <li class="widget-manager-item" draggable="true" data-widget-item="<?= e($id) ?>">
                        <span class="widget-manager-handle" aria-hidden="true">⋮⋮</span>
                        <label class="widget-manager-label">
                            <input type="checkbox" data-widget-visible="<?= e($id) ?>" <?= isset($hidden[$id]) ? '' : 'checked' ?>>
                            <span><?= e((string) $widgets[$id]) ?></span>
                        </label>
                        <span class="widget-manager-moves">
                            <button type="button" class="widget-manager-move widget-manager-width"
                                data-widget-width="<?= e($id) ?>"
                                aria-label="<?= e(__('widgets.width')) ?>"
                                title="<?= e(__('widgets.width')) ?>"><?= (int) $sizeOf($id) ?>/3</button>
                            <button type="button" class="widget-manager-move" data-widget-up aria-label="<?= e(__('widgets.move_up')) ?>">↑</button>
                            <button type="button" class="widget-manager-move" data-widget-down aria-label="<?= e(__('widgets.move_down')) ?>">↓</button>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </details>
</div>
