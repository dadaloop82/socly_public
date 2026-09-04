<?php
/**
 * @var array<string, array{role:array,people:list<array>}> $byRole
 * @var string $assocName
 * @var string $assocLegalCode
 * @var string $assocLegalLabel
 * @var bool $canEdit
 * @var list<array> $customOrgans
 * @var int $votingMembersCount
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
    $endsRaw = trim((string) ($person['mandate_ends_at'] ?? ''));
    $ends = format_date($endsRaw !== '' ? $endsRaw : null);
    $hasMandate = $appointed !== '' || $ends !== '';
    $mandateExpired = $endsRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $endsRaw) === 1
        && substr($endsRaw, 0, 10) < date('Y-m-d');
    $id = (int) ($person['id'] ?? 0);
    $tag = $editable ? 'a' : 'article';
    $href = $editable ? ' href="' . e(url('/org/people/' . $id . '/edit')) . '"' : '';
    $class = 'org-node' . ($variant !== '' ? ' org-node--' . $variant : '') . ($editable ? ' org-node--clickable' : '');
    if ($mandateExpired) {
        $class .= ' org-node--mandate-expired';
    }
    ?>
    <<?= $tag ?> class="<?= e($class) ?>"<?= $href ?><?= $editable ? ' title="' . e(__('org.edit_person')) . '"' : '' ?>>
        <p class="org-node-role"><?= e($roleLabelText) ?></p>
        <h3 class="org-node-name"><?= e($name !== '' ? $name : __('org.vacant')) ?></h3>
        <?php if ($mandateExpired): ?>
            <span class="org-mandate-badge"><?= e(__('org.mandate_expired')) ?></span>
        <?php endif; ?>
        <?php if ($hasMandate): ?>
            <p class="org-node-mandate">
                <?= e(__('org.mandate')) ?>:
                <?= e($appointed !== '' ? $appointed : '—') ?>
                →
                <?= e($ends !== '' ? $ends : '—') ?>
            </p>
        <?php endif; ?>
        <?php
        $minutesRef = trim((string) ($person['appointment_minutes'] ?? ''));
        if ($minutesRef !== ''):
        ?>
            <p class="org-node-minutes muted"><?= e($minutesRef) ?></p>
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
    bool $isCustom = false,
    bool $wrapTier = true
) use ($roleLabel, $renderPersonCard, $renderVacant, $renderAddNode): void {
    $label = $roleLabel($group);
    $roleKey = (string) (($group['role']['key'] ?? ''));
    $people = $group['people'] ?? [];
    $mod = $variant !== '' ? ' org-group--' . $variant : '';
    $inner = static function () use ($label, $roleKey, $people, $mod, $variant, $editable, $isCustom, $renderPersonCard, $renderVacant, $renderAddNode): void {
        ?>
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
        <?php
    };
    if (!$wrapTier) {
        $inner();
        return;
    }
    ?>
    <div class="org-tier org-tier--group">
        <?php $inner(); ?>
    </div>
    <?php
};

$renderRoleSlot = static function (
    string $roleKey,
    string $variant,
    bool $editable
) use ($byRole, $roleLabel, $renderPersonCard, $renderVacant, $renderAddNode): void {
    if (!isset($byRole[$roleKey])) {
        return;
    }
    $group = $byRole[$roleKey];
    $label = $roleLabel($group);
    $people = $group['people'] ?? [];
    if ($people === []) {
        $renderVacant($label, $roleKey, $variant, $editable);
        return;
    }
    foreach ($people as $person) {
        $renderPersonCard($person, $label, $variant, $editable);
    }
    if ($editable) {
        $renderAddNode($label, $roleKey);
    }
};

$directorOfficerKeys = ['president', 'vice_president', 'secretary', 'treasurer'];
$independentKeys = ['auditor', 'ombudsman'];
$board = $byRole['board'] ?? ['role' => ['key' => 'board', 'label_key' => 'association.role_board_director'], 'people' => []];
$customOrgans = $customOrgans ?? [];
$votingMembersCount = (int) ($votingMembersCount ?? 0);
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
                <label class="checkbox-row org-hide-vacant-toggle">
                    <input type="checkbox" data-org-hide-vacant>
                    <span><?= e(__('org.hide_vacant')) ?></span>
                </label>
                <a class="btn btn-ghost" href="<?= e(url('/org/history')) ?>"><?= e(__('org.history_title')) ?></a>
                <a class="btn btn-ghost" href="<?= e(url('/org/export.csv')) ?>"><?= e(__('org.export_csv')) ?></a>
                <a class="btn" href="<?= e(url('/org/people/create')) ?>"><?= e(__('org.add_person')) ?></a>
            </div>
        <?php else: ?>
            <div class="actions org-chart-actions">
                <label class="checkbox-row org-hide-vacant-toggle">
                    <input type="checkbox" data-org-hide-vacant>
                    <span><?= e(__('org.hide_vacant')) ?></span>
                </label>
                <a class="btn btn-ghost" href="<?= e(url('/org/export.csv')) ?>"><?= e(__('org.export_csv')) ?></a>
            </div>
        <?php endif; ?>
    </div>

    <div class="org-chart" data-org-chart aria-label="<?= e(__('org.chart_title')) ?>">
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

        <div class="org-tier org-tier--assembly">
            <article class="org-node org-node--assembly">
                <p class="org-node-role"><?= e(__('org.general_assembly')) ?></p>
                <h3 class="org-node-name"><?= e(__('org.general_assembly_title')) ?></h3>
                <?php if ($votingMembersCount > 0): ?>
                    <p class="org-node-meta"><?= e(__('org.voting_members_count', ['count' => (string) $votingMembersCount])) ?></p>
                <?php else: ?>
                    <p class="org-node-meta muted"><?= e(__('org.voting_members_empty')) ?></p>
                <?php endif; ?>
            </article>
        </div>

        <div class="org-spine" aria-hidden="true"></div>

        <div class="org-tier org-tier--group">
            <div class="org-group org-group--directors">
                <div class="org-group-head">
                    <h3 class="org-group-title"><?= e(__('org.board_of_directors')) ?></h3>
                    <p class="org-group-lede"><?= e(__('org.board_of_directors_lede')) ?></p>
                </div>
                <div class="org-board-officers">
                    <?php foreach ($directorOfficerKeys as $officerKey): ?>
                        <div class="org-board-officer-slot">
                            <?php $renderRoleSlot($officerKey, 'exec', $canEdit); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="org-board-members">
                    <h4 class="org-board-members-title"><?= e(__('org.board_members_section')) ?></h4>
                    <div class="org-group-nodes">
                        <?php
                        $boardLabel = $roleLabel($board);
                        $boardPeople = $board['people'] ?? [];
                        if ($boardPeople === []) {
                            $renderVacant($boardLabel, 'board', 'board', $canEdit);
                        } else {
                            foreach ($boardPeople as $person) {
                                $renderPersonCard($person, $boardLabel, 'board', $canEdit);
                            }
                            if ($canEdit) {
                                $renderAddNode($boardLabel, 'board');
                            }
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="org-spine" aria-hidden="true"></div>

        <div class="org-tier org-tier--independent">
            <p class="org-independent-label"><?= e(__('org.independent_organs')) ?></p>
            <div class="org-independent-fork" aria-hidden="true">
                <span class="org-independent-fork-stem"></span>
                <span class="org-independent-fork-bar"></span>
                <span class="org-independent-fork-leg org-independent-fork-leg--left"></span>
                <span class="org-independent-fork-leg org-independent-fork-leg--right"></span>
            </div>
            <div class="org-independent-split">
                <?php foreach ($independentKeys as $indKey): ?>
                    <?php
                    $indGroup = $byRole[$indKey] ?? [
                        'role' => [
                            'key' => $indKey,
                            'label_key' => 'association.role_' . ($indKey === 'ombudsman' ? 'ombudsman' : 'auditor'),
                        ],
                        'people' => [],
                    ];
                    ?>
                    <div class="org-independent-branch">
                        <?php $renderGroupBlock($indGroup, $indKey === 'auditor' ? 'audit' : 'ombudsman', $canEdit, false, false); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($customOrgans !== []): ?>
            <div class="org-spine" aria-hidden="true"></div>
            <div class="org-tier org-tier--commissions">
                <p class="org-commissions-label"><?= e(__('org.operational_commissions')) ?></p>
            </div>
        <?php endif; ?>

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

        <?php if ($canEdit): ?>
            <div class="org-spine" aria-hidden="true"></div>
            <div class="org-tier org-tier--add-organ">
                <form class="org-add-organ-form org-add-organ-footer" method="post" action="<?= e(url('/org/organs')) ?>">
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
            </div>
        <?php endif; ?>
    </div>
</div>
