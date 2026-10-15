<?php
/**
 * @var array<string,mixed>|null $person
 * @var array<string,mixed> $values
 * @var list<array> $roles
 * @var array<string,mixed>|null $roleMeta
 * @var array<string,mixed>|null $roleConflict
 * @var bool $isEdit
 * @var list<array> $memberOptions
 */
use Socly\Services\AssociationPeopleService;

$roleKey = (string) ($values['role_key'] ?? 'board');
$memberRaw = trim((string) ($values['member_id'] ?? ''));
$memberId = ($memberRaw === '' || $memberRaw === '0')
    ? AssociationPeopleService::MEMBER_EXTERNAL
    : $memberRaw;
$memberOptions = $memberOptions ?? [];
$requiresResidence = !empty($roleMeta['requires_residence']);
$requiresMandate = !empty($roleMeta['requires_mandate']);
$action = $isEdit
    ? url('/org/people/' . (int) ($person['id'] ?? 0))
    : url('/org/people');

$roleSelectOptions = [];
foreach ($roles as $role) {
    $optLabel = trim((string) ($role['custom_label'] ?? ''));
    if ($optLabel === '') {
        $optLabel = __((string) ($role['label_key'] ?? ''));
    }
    $optKey = (string) ($role['key'] ?? '');
    $roleSelectOptions[] = [
        'value' => $optKey,
        'label' => $optLabel,
        'attrs' => ['data-requires-residence' => !empty($role['requires_residence']) ? '1' : '0'],
    ];
}

$memberSelectOptions = [
    [
        'value' => AssociationPeopleService::MEMBER_EXTERNAL,
        'label' => (string) __('org.member_external'),
    ],
];
foreach ($memberOptions as $memberOption) {
    $optId = (int) ($memberOption['id'] ?? 0);
    $optLabel = trim((string) ($memberOption['display_name'] ?? ''));
    if ($optLabel === '') {
        $optLabel = trim((string) (($memberOption['last_name'] ?? '') . ' ' . ($memberOption['first_name'] ?? '')));
    }
    $memberSelectOptions[] = ['value' => (string) $optId, 'label' => $optLabel];
}
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e($isEdit ? __('org.edit_person') : __('org.add_person')) ?></h1>
        <p class="page-lede"><?= e(__('org.person_form_lede')) ?></p>
    </div>
</div>

<form
    id="org-person-form"
    class="panel org-person-form"
    method="post"
    action="<?= e($action) ?>"
    data-cities-url="<?= e(url('/api/geo/cities')) ?>"
    data-addresses-url="<?= e(url('/api/geo/addresses')) ?>"
    data-org-person-form
    data-member-external="<?= e(AssociationPeopleService::MEMBER_EXTERNAL) ?>"
    data-org-member-profile-url="<?= e(url('/api/org/members/__ID__/profile')) ?>"
    data-msg-role-incompatible="<?= e(__('org.role_incompatible_warn')) ?>"
    <?php if ($isEdit && !empty($person['id'])): ?>
    data-org-person-id="<?= (int) $person['id'] ?>"
    <?php endif; ?>
    data-leave-guard
    data-cf-url="<?= e(url('/api/fiscal-code')) ?>"
    data-csrf="<?= e(csrf_token()) ?>"
    <?php if (!empty($roleConflict)): ?>
    data-role-conflict="<?= e(json_encode($roleConflict, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>"
    data-msg-role-conflict="<?= e(__('org.role_conflict_message')) ?>"
    data-msg-role-replace="<?= e(__('org.role_conflict_replace')) ?>"
    <?php endif; ?>
>
    <?= csrf_field() ?>
    <div class="member-profile-block">
        <h3 class="member-profile-block-title"><?= e(__('org.form_section_role')) ?></h3>
        <div class="org-field-row">
            <?= field('select', [
                'name' => 'role_key',
                'label' => (string) __('settings.people_role'),
                'value' => $roleKey,
                'required' => true,
                'include_empty' => false,
                'options' => $roleSelectOptions,
                'attrs' => ['data-org-role-select' => true],
            ]) ?>
            <?= field('select', [
                'name' => 'member_id',
                'label' => (string) __('org.field_member'),
                'value' => $memberId,
                'required' => true,
                'include_empty' => false,
                'options' => $memberSelectOptions,
                'attrs' => ['data-org-member-select' => true],
            ]) ?>
        </div>
        <p class="muted org-member-hint"><?= e(__('org.member_select_lede')) ?></p>
    </div>

    <div class="member-profile-block" data-org-copy-fields>
        <h3 class="member-profile-block-title"><?= e(__('org.form_section_person')) ?></h3>
        <p class="muted org-locked-hint" data-org-locked-hint hidden><?= e(__('org.person_fields_locked_hint')) ?></p>
        <div class="org-field-row">
            <?= field('text', [
                'name' => 'first_name',
                'label' => (string) __('setup.field_first_name'),
                'value' => (string) ($values['first_name'] ?? ''),
                'required' => true,
                'maxlength' => 120,
                'attrs' => ['data-first-name' => true, 'data-org-member-field' => true],
            ]) ?>
            <?= field('text', [
                'name' => 'last_name',
                'label' => (string) __('setup.field_last_name'),
                'value' => (string) ($values['last_name'] ?? ''),
                'required' => true,
                'maxlength' => 120,
                'attrs' => ['data-last-name' => true, 'data-org-member-field' => true],
            ]) ?>
        </div>
        <div class="org-field-row">
            <?= field('gender', [
                'name' => 'gender',
                'label' => (string) __('setup.field_gender'),
                'value' => (string) ($values['gender'] ?? ''),
                'attrs' => ['data-org-member-field' => true],
            ]) ?>
            <?= field('birth_date', [
                'name' => 'birth_date',
                'label' => (string) __('setup.field_birth_date'),
                'value' => (string) ($values['birth_date'] ?? ''),
                'show_age' => false,
                'attrs' => ['data-org-member-field' => true],
            ]) ?>
        </div>
        <div class="org-field-row org-field-row--single">
            <div class="geo-birth-host" data-field="birth_place">
                <?= field('birth_place', [
                    'name' => 'birth_place',
                    'value' => (string) ($values['birth_place'] ?? ''),
                    'input_class' => 'member-geo-birth',
                    'flag_name' => 'birth_place_foreign',
                    'foreign_inline' => true,
                    'context' => 'member',
                ]) ?>
            </div>
        </div>
        <div class="org-field-row org-field-row--single">
            <?= field('fiscal_code', [
                'name' => 'fiscal_code',
                'label' => (string) __('setup.field_person_fiscal_code'),
                'value' => (string) ($values['fiscal_code'] ?? ''),
                'required' => true,
                'attrs' => ['data-org-member-field' => true],
            ]) ?>
        </div>
        <div class="geo-address-host" data-field="residence" data-org-residence-fields <?= $requiresResidence ? '' : 'hidden' ?>>
            <?= field('address', [
                'show_hint' => true,
                'required_map' => [
                    'city' => $requiresResidence,
                    'postal_code' => $requiresResidence,
                    'address' => $requiresResidence,
                    'house_number' => $requiresResidence,
                ],
                'names' => [
                    'city' => 'city',
                    'postal_code' => 'postal_code',
                    'address' => 'address',
                    'house_number' => 'house_number',
                ],
                'values' => [
                    'city' => (string) ($values['city'] ?? ''),
                    'postal_code' => (string) ($values['postal_code'] ?? ''),
                    'address' => (string) ($values['address'] ?? ''),
                    'house_number' => (string) ($values['house_number'] ?? ''),
                ],
            ]) ?>
        </div>
    </div>

    <div class="member-profile-block">
        <h3 class="member-profile-block-title"><?= e(__('org.form_section_contacts')) ?></h3>
        <div class="org-field-row">
            <?= field('email', [
                'name' => 'email',
                'label' => (string) __('org.field_email'),
                'value' => (string) ($values['email'] ?? ''),
                'maxlength' => 190,
                'attrs' => ['data-org-member-field' => true],
            ]) ?>
            <?= field('phone', [
                'name' => 'phone',
                'label' => (string) __('setup.field_phone'),
                'value' => (string) ($values['phone'] ?? ''),
                'input_class' => '',
            ]) ?>
        </div>
        <div class="org-field-row org-field-row--single">
            <?= field('text', [
                'name' => 'notes',
                'label' => (string) __('org.notes'),
                'value' => (string) ($values['notes'] ?? ''),
            ]) ?>
        </div>
    </div>

    <?= field('hidden', [
        'name' => 'replace_unique_role',
        'value' => '',
        'attrs' => ['data-org-replace-unique' => true],
    ]) ?>

    <div class="member-profile-block">
        <h3 class="member-profile-block-title"><?= e(__('org.form_section_mandate')) ?></h3>
        <div class="org-field-row">
        <?= field('date', [
            'name' => 'appointed_at',
            'label' => (string) __('setup.field_appointed_at'),
            'value' => (string) ($values['appointed_at'] ?? ''),
            'required' => $requiresMandate,
        ]) ?>
        <?= field('date', [
            'name' => 'mandate_ends_at',
            'label' => (string) __('setup.field_mandate_ends_at'),
            'value' => (string) ($values['mandate_ends_at'] ?? ''),
            'required' => $requiresMandate,
        ]) ?>
        </div>
        <div class="org-field-row org-field-row--single">
            <?= field('text', [
                'name' => 'appointment_minutes',
                'label' => (string) __('org.appointment_minutes'),
                'value' => (string) ($values['appointment_minutes'] ?? ''),
                'maxlength' => 190,
                'placeholder' => (string) __('org.appointment_minutes_placeholder'),
            ]) ?>
        </div>
    </div>

    <div class="form-actions form-actions-end">
        <a class="btn btn-ghost" href="<?= e(url('/org')) ?>"><?= e(__('common.back')) ?></a>
        <button class="btn" type="submit"><?= e($isEdit ? __('org.update_person') : __('org.create_person')) ?></button>
    </div>
</form>

<?php if ($isEdit): ?>
    <form class="org-person-delete" method="post" action="<?= e(url('/org/people/' . (int) ($person['id'] ?? 0) . '/delete')) ?>" data-confirm="<?= e(__('org.delete_confirm')) ?>" data-confirm-danger="1">
        <?= csrf_field() ?>
        <button class="btn btn-danger btn-sm" type="submit"><?= e(__('org.delete_person')) ?></button>
    </form>
<?php endif; ?>
