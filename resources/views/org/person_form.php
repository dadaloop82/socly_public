<?php
/**
 * @var array<string,mixed>|null $person
 * @var array<string,mixed> $values
 * @var list<array> $roles
 * @var array<string,mixed>|null $roleMeta
 * @var bool $isEdit
 */
$roleKey = (string) ($values['role_key'] ?? 'board');
$requiresResidence = !empty($roleMeta['requires_residence']);
$requiresMandate = !empty($roleMeta['requires_mandate']);
$action = $isEdit
    ? url('/org/people/' . (int) ($person['id'] ?? 0))
    : url('/org/people');
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e($isEdit ? __('org.edit_person') : __('org.add_person')) ?></h1>
        <p class="page-lede"><?= e(__('org.person_form_lede')) ?></p>
    </div>
</div>

<form
    class="panel"
    method="post"
    action="<?= e($action) ?>"
    data-cities-url="<?= e(url('/api/geo/cities')) ?>"
    data-addresses-url="<?= e(url('/api/geo/addresses')) ?>"
    data-org-person-form
    data-leave-guard
    data-cf-url="<?= e(url('/api/fiscal-code')) ?>"
    data-csrf="<?= e(csrf_token()) ?>"
>
    <?= csrf_field() ?>
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('org.person_details')) ?></h2>
            <p class="section-lede"><?= e(__('org.person_details_lede')) ?></p>
        </div>
        <div class="actions">
            <a class="btn btn-ghost" href="<?= e(url('/org')) ?>"><?= e(__('common.back')) ?></a>
            <button class="btn" type="submit"><?= e($isEdit ? __('org.update_person') : __('org.create_person')) ?></button>
        </div>
    </div>

    <div class="grid-3">
        <div>
            <label><?= e(__('settings.people_role')) ?> *</label>
            <select name="role_key" required>
                <?php foreach ($roles as $role): ?>
                    <?php
                    $optLabel = trim((string) ($role['custom_label'] ?? ''));
                    if ($optLabel === '') {
                        $optLabel = __((string) ($role['label_key'] ?? ''));
                    }
                    ?>
                    <option value="<?= e((string) $role['key']) ?>" <?= $roleKey === (string) $role['key'] ? 'selected' : '' ?>>
                        <?= e($optLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label><?= e(__('setup.field_first_name')) ?> *</label>
            <input type="text" name="first_name" value="<?= e((string) ($values['first_name'] ?? '')) ?>" required maxlength="120" data-first-name>
        </div>
        <div>
            <label><?= e(__('setup.field_last_name')) ?> *</label>
            <input type="text" name="last_name" value="<?= e((string) ($values['last_name'] ?? '')) ?>" required maxlength="120" data-last-name>
        </div>
    </div>

    <div class="grid-3">
        <div>
            <label><?= e(__('setup.field_gender')) ?></label>
            <select name="gender" data-gender-input>
                <option value="">—</option>
                <option value="M" <?= ($values['gender'] ?? '') === 'M' ? 'selected' : '' ?>><?= e(__('members.gender_m')) ?></option>
                <option value="F" <?= ($values['gender'] ?? '') === 'F' ? 'selected' : '' ?>><?= e(__('members.gender_f')) ?></option>
                <option value="X" <?= ($values['gender'] ?? '') === 'X' ? 'selected' : '' ?>><?= e(__('members.gender_x')) ?></option>
            </select>
        </div>
        <?= view_partial('partials/geo_birth_place', [
            'name' => 'birth_place',
            'value' => (string) ($values['birth_place'] ?? ''),
        ]) ?>
        <div>
            <label><?= e(__('setup.field_birth_date')) ?></label>
            <input type="date" name="birth_date" value="<?= e((string) ($values['birth_date'] ?? '')) ?>" data-birth-date>
        </div>
    </div>

    <div class="grid-3">
        <div>
            <label><?= e(__('setup.field_person_fiscal_code')) ?> *</label>
            <input type="text" name="fiscal_code" value="<?= e((string) ($values['fiscal_code'] ?? '')) ?>" required maxlength="16" pattern="[A-Za-z0-9]{16}" autocomplete="off" data-fiscal-code placeholder="<?= e(__('members.cf_hint')) ?>">
            <button class="btn btn-ghost btn-sm" type="button" data-cf-generate><?= e(__('members.cf_generate')) ?></button>
            <p class="muted" data-cf-status
               data-ready="<?= e(__('members.cf_ready')) ?>"
               data-incomplete="<?= e(__('members.cf_incomplete')) ?>"
               data-gender-other="<?= e(__('members.cf_gender_other')) ?>"
               hidden></p>
        </div>
        <div>
            <label><?= e(__('setup.field_email')) ?></label>
            <input type="email" name="email" value="<?= e((string) ($values['email'] ?? '')) ?>" maxlength="190">
        </div>
        <div>
            <label><?= e(__('setup.field_phone')) ?></label>
            <input type="text" name="phone" value="<?= e((string) ($values['phone'] ?? '')) ?>" maxlength="40">
        </div>
    </div>
    <div>
        <div>
            <label><?= e(__('org.notes')) ?></label>
            <input type="text" name="notes" value="<?= e((string) ($values['notes'] ?? '')) ?>">
        </div>
    </div>

    <?= view_partial('partials/geo_address', [
        'show_hint' => true,
        'required' => [
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

    <div class="grid-2" style="margin-top:1rem">
        <div>
            <label><?= e(__('setup.field_appointed_at')) ?><?= $requiresMandate ? ' *' : '' ?></label>
            <input type="date" name="appointed_at" value="<?= e((string) ($values['appointed_at'] ?? '')) ?>" <?= $requiresMandate ? 'required' : '' ?>>
        </div>
        <div>
            <label><?= e(__('setup.field_mandate_ends_at')) ?><?= $requiresMandate ? ' *' : '' ?></label>
            <input type="date" name="mandate_ends_at" value="<?= e((string) ($values['mandate_ends_at'] ?? '')) ?>" <?= $requiresMandate ? 'required' : '' ?>>
        </div>
    </div>
    <div class="form-actions form-actions-end">
        <a class="btn btn-ghost" href="<?= e(url('/org')) ?>"><?= e(__('common.back')) ?></a>
        <button class="btn" type="submit"><?= e($isEdit ? __('org.update_person') : __('org.create_person')) ?></button>
    </div>
</form>

<?php if ($isEdit): ?>
    <form class="panel" method="post" action="<?= e(url('/org/people/' . (int) ($person['id'] ?? 0) . '/delete')) ?>" data-confirm="<?= e(__('org.delete_confirm')) ?>" data-confirm-danger="1">
        <?= csrf_field() ?>
        <div class="panel-header">
            <div>
                <h2 class="section-title"><?= e(__('org.delete_person')) ?></h2>
                <p class="section-lede"><?= e(__('org.delete_person_lede')) ?></p>
            </div>
            <button class="btn btn-ghost" type="submit"><?= e(__('org.delete_person')) ?></button>
        </div>
    </form>
<?php endif; ?>
