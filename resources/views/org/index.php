<?php
/**
 * @var array<string, array{role:array,people:list<array>}> $byRole
 * @var string $assocName
 * @var string $assocLegalCode
 * @var string $assocLegalLabel
 * @var bool $canEdit
 * @var list<array> $customOrgans
 */
$canEdit = !empty($canEdit);
$assocLegalCode = strtoupper(trim((string) ($assocLegalCode ?? '')));
$assocLegalLabel = trim((string) ($assocLegalLabel ?? ''));
$hasLogo = assoc_logo_url() !== null;
$roleLabel = static function (array $group): string {
    $role = $group['role'] ?? [];
    $custom = trim((string) ($role['custom_label'] ?? ''));
    if ($custom !== '') {
        return $custom;
    }
    $key = (string) ($role['key'] ?? '');
    return __((string) ($role['label_key'] ?? ('association.role_' . $key)));
};
$personName = static function (array $person): string {
    return trim((string) (($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')));
};
$renderPersonCard = static function (
    array $person,
    string $roleLabelText,
    string $variant = '',
    bool $editable = false
) use ($personName): void {
    $name = $personName($person);
    $appointed = format_date($person['appointed_at'] ?? null);
    $ends = format_date($person['mandate_ends_at'] ?? null);
    $hasMandate = $appointed !== '' || $ends !== '';
    $id = (int) ($person['id'] ?? 0);
    $tag = $editable ? 'a' : 'article';
    $href = $editable ? ' href="' . e(url('/org/people/' . $id . '/edit')) . '"' : '';
    $class = 'org-node' . ($variant !== '' ? ' org-node--' . $variant : '') . ($editable ? ' org-node--clickable' : '');
    ?>
    <<?= $tag ?> class="<?= e($class) ?>"<?= $href ?><?= $editable ? ' title="' . e(__('org.edit_person')) . '"' : '' ?>>
        <p class="org-node-role"><?= e($roleLabelText) ?></p>
        <h3 class="org-node-name"><?= e($name !== '' ? $name : __('org.vacant')) ?></h3>
        <?php if ($hasMandate): ?>
            <p class="org-node-mandate">
                <?= e(__('org.mandate')) ?>:
                <?= e($appointed !== '' ? $appointed : '—') ?>
                →
                <?= e($ends !== '' ? $ends : '—') ?>
            </p>
        <?php endif; ?>
        <?php if ($editable): ?>
            <span class="org-node-action"><?= e(__('org.tap_to_edit')) ?></span>
        <?php endif; ?>
    </<?= $tag ?>>
    <?php
};
$renderVacant = static function (
    string $roleLabelText,
    string $roleKey,
    string $variant = '',
    bool $editable = false
): void {
    $tag = $editable ? 'a' : 'article';
    $href = $editable ? ' href="' . e(url('/org/people/create?role=' . rawurlencode($roleKey))) . '"' : '';
    $class = 'org-node org-node--vacant' . ($variant !== '' ? ' org-node--' . $variant : '') . ($editable ? ' org-node--clickable' : '');
    ?>
    <<?= $tag ?> class="<?= e($class) ?>"<?= $href ?><?= $editable ? ' title="' . e(__('org.add_person')) . '"' : '' ?>>
        <p class="org-node-role"><?= e($roleLabelText) ?></p>
        <h3 class="org-node-name"><?= e(__('org.vacant')) ?></h3>
        <?php if ($editable): ?>
            <span class="org-node-action"><?= e(__('org.tap_to_add')) ?></span>
        <?php endif; ?>
    </<?= $tag ?>>
    <?php
};
$renderAddNode = static function (string $roleLabelText, string $roleKey): void {
    ?>
    <a class="org-node org-node--add org-node--clickable" href="<?= e(url('/org/people/create?role=' . rawurlencode($roleKey))) ?>">
        <p class="org-node-role"><?= e($roleLabelText) ?></p>
        <h3 class="org-node-name">+ <?= e(__('org.add_person')) ?></h3>
        <span class="org-node-action"><?= e(__('org.tap_to_add')) ?></span>
    </a>
    <?php
};
$renderGroupBlock = static function (
    array $group,
    string $variant,
    bool $editable,
    bool $isCustom = false
) use ($roleLabel, $renderPersonCard, $renderVacant, $renderAddNode): void {
    $label = $roleLabel($group);
    $roleKey = (string) (($group['role']['key'] ?? ''));
    $people = $group['people'] ?? [];
    $mod = $variant !== '' ? ' org-group--' . $variant : '';
    ?>
    <div class="org-tier org-tier--group">
        <div class="org-group<?= e($mod) ?>">
            <div class="org-group-head">
                <h3 class="org-group-title"><?= e($label) ?></h3>
                <?php if ($editable && $isCustom && $people === []): ?>
                    <form method="post" action="<?= e(url('/org/organs/' . rawurlencode($roleKey) . '/delete')) ?>" class="org-group-delete" data-confirm="<?= e(__('org.delete_organ_confirm')) ?>" data-confirm-danger="1">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-ghost btn-sm"><?= e(__('org.delete_organ')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="org-group-nodes">
                <?php
                if ($people === []) {
                    $renderVacant($label, $roleKey, $variant !== '' ? $variant : 'board', $editable);
                } else {
                    foreach ($people as $person) {
                        $renderPersonCard($person, $label, $variant !== '' ? $variant : 'board', $editable);
                    }
                    if ($editable) {
                        $renderAddNode($label, $roleKey);
                    }
                }
                ?>
            </div>
        </div>
    </div>
    <?php
};

$president = $byRole['president'] ?? ['role' => ['key' => 'president', 'label_key' => 'association.role_president'], 'people' => []];
$execKeys = ['vice_president', 'secretary', 'treasurer'];
$board = $byRole['board'] ?? ['role' => ['key' => 'board', 'label_key' => 'association.role_board'], 'people' => []];
$auditor = $byRole['auditor'] ?? ['role' => ['key' => 'auditor', 'label_key' => 'association.role_auditor'], 'people' => []];
$customOrgans = $customOrgans ?? [];
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('org.title')) ?></h1>
        <p class="page-lede"><?= e(__('org.lede')) ?></p>
    </div>
</div>

<div class="panel org-chart-panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('org.chart_title')) ?></h2>
            <p class="section-lede"><?= e(__('org.chart_lede')) ?></p>
        </div>
        <?php if ($canEdit): ?>
            <div class="actions org-chart-actions">
                <form class="org-add-organ-form" method="post" action="<?= e(url('/org/organs')) ?>">
                    <?= csrf_field() ?>
                    <label class="visually-hidden" for="org-organ-label"><?= e(__('org.add_organ')) ?></label>
                    <input
                        id="org-organ-label"
                        type="text"
                        name="label"
                        maxlength="80"
                        required
                        placeholder="<?= e(__('org.add_organ_placeholder')) ?>"
                        autocomplete="off"
                    >
                    <button class="btn btn-ghost" type="submit"><?= e(__('org.add_organ')) ?></button>
                </form>
                <a class="btn" href="<?= e(url('/settings#people')) ?>"><?= e(__('org.edit_officers')) ?></a>
            </div>
        <?php endif; ?>
    </div>

    <div class="org-chart" aria-label="<?= e(__('org.chart_title')) ?>">
        <div class="org-tier org-tier--root">
            <div class="org-assoc-root<?= $hasLogo ? ' has-logo' : '' ?>">
                <?php if ($hasLogo): ?>
                    <div class="org-assoc-logo">
                        <?= assoc_logo_img('org-assoc-logo-img', (string) $assocName) ?>
                    </div>
                <?php endif; ?>
                <div class="org-assoc-text">
                    <span class="org-assoc-label"><?= e(__('org.association')) ?></span>
                    <strong class="org-assoc-name"><?= e($assocName) ?></strong>
                    <?php if ($assocLegalCode !== ''): ?>
                        <span class="org-assoc-legal">
                            <span class="org-assoc-legal-code"><?= e($assocLegalCode) ?></span>
                            <?php if ($assocLegalLabel !== ''): ?>
                                <span class="org-assoc-legal-label"><?= e($assocLegalLabel) ?></span>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="org-spine" aria-hidden="true"></div>

        <div class="org-tier org-tier--president">
            <?php
            $presPeople = $president['people'] ?? [];
            $presLabel = $roleLabel($president);
            if ($presPeople === []) {
                $renderVacant($presLabel, 'president', 'president', $canEdit);
            } else {
                foreach ($presPeople as $person) {
                    $renderPersonCard($person, $presLabel, 'president', $canEdit);
                }
            }
            ?>
        </div>

        <div class="org-fork" aria-hidden="true">
            <div class="org-fork-stem"></div>
            <div class="org-fork-bar">
                <span class="org-fork-corner org-fork-corner--left"></span>
                <span class="org-fork-junction"></span>
                <span class="org-fork-corner org-fork-corner--right"></span>
            </div>
            <div class="org-fork-legs">
                <span class="org-fork-leg"></span>
                <span class="org-fork-leg"></span>
                <span class="org-fork-leg"></span>
            </div>
        </div>

        <div class="org-tier org-tier--exec">
            <?php foreach ($execKeys as $key): ?>
                <?php if (!isset($byRole[$key])) {
                    continue;
                } ?>
                <?php
                $execGroup = $byRole[$key];
                $execLabel = $roleLabel($execGroup);
                $execPeople = $execGroup['people'] ?? [];
                ?>
                <div class="org-tier-slot">
                    <?php
                    if ($execPeople === []) {
                        $renderVacant($execLabel, $key, 'exec', $canEdit);
                    } else {
                        foreach ($execPeople as $person) {
                            $renderPersonCard($person, $execLabel, 'exec', $canEdit);
                        }
                        if ($canEdit) {
                            $renderAddNode($execLabel, $key);
                        }
                    }
                    ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="org-spine" aria-hidden="true"></div>
        <?php $renderGroupBlock($board, 'board', $canEdit, false); ?>

        <div class="org-spine" aria-hidden="true"></div>
        <?php $renderGroupBlock($auditor, 'audit', $canEdit, false); ?>

        <?php foreach ($customOrgans as $organRole): ?>
            <?php
            $organKey = (string) ($organRole['key'] ?? '');
            if ($organKey === '') {
                continue;
            }
            $organGroup = $byRole[$organKey] ?? ['role' => $organRole, 'people' => []];
            ?>
            <div class="org-spine" aria-hidden="true"></div>
            <?php $renderGroupBlock($organGroup, 'custom', $canEdit, true); ?>
        <?php endforeach; ?>
    </div>
</div>
