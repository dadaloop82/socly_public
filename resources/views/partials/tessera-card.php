<?php

/**
 * Membership card (front face) — shared by the member wizard preview and the member detail page.
 *
 * @var string $name      Member display name
 * @var string $number    Membership-card number (= member number)
 * @var string $role      Localised role label ("Socio" / "Member")
 * @var string $type      Localised membership type
 * @var string $photo_url Member photo URL, empty when there is no photo
 * @var string $gender    m|f
 * @var string $logo_url  Association logo URL, empty to hide the watermark
 * @var bool   $live      Expose the data-* hooks updated live by the member wizard
 */

$name = trim((string) ($name ?? ''));
$number = trim((string) ($number ?? ''));
$role = trim((string) ($role ?? __('members.card_role')));
$type = trim((string) ($type ?? ''));
$photoUrl = trim((string) ($photo_url ?? ''));
$gender = strtolower(trim((string) ($gender ?? 'm'))) === 'f' ? 'f' : 'm';
$logoUrl = trim((string) ($logo_url ?? ''));
$live = !empty($live);

$hook = static function (string $attribute) use ($live): string {
    return $live ? ' ' . $attribute : '';
};
?>
<div class="tessera-card"<?= $logoUrl !== '' ? ' style="--tessera-logo:url(\'' . e($logoUrl) . '\')"' : '' ?>>
    <div class="tessera-card-bg" aria-hidden="true"></div>
    <?php if ($logoUrl !== ''): ?>
        <div class="tessera-card-mark" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="tessera-card-shine" aria-hidden="true"></div>
    <div class="tessera-card-face">
        <div class="tessera-card-top">
            <div class="tessera-brand">
                <?= assoc_lockup_html(['class' => 'assoc-lockup-tessera']) ?: socly_mark_img('tessera-mark', 'SOCLY', 'light') ?>
            </div>
        </div>
        <div class="tessera-card-main">
            <div class="tessera-photo<?= $photoUrl === '' ? ' is-empty' : '' ?>"<?= $hook('data-tessera-photo-wrap') ?>>
                <?php if ($photoUrl !== ''): ?>
                    <img class="tessera-photo-img"<?= $hook('data-tessera-photo') ?> src="<?= e($photoUrl) ?>" alt="">
                    <span
                        class="tessera-photo-placeholder photo-placeholder"
                        <?= $hook('data-photo-placeholder data-tessera-photo-placeholder') ?>
                        data-photo-gender="<?= e($gender) ?>"
                        hidden
                    ></span>
                <?php else: ?>
                    <img class="tessera-photo-img"<?= $hook('data-tessera-photo') ?> alt="" hidden>
                    <span
                        class="tessera-photo-placeholder photo-placeholder"
                        <?= trim($hook('data-photo-placeholder data-tessera-photo-placeholder')) ?>
                        data-photo-gender="<?= e($gender) ?>"
                    ></span>
                <?php endif; ?>
            </div>
            <div class="tessera-meta">
                <span class="tessera-name"<?= $hook('data-tessera-name') ?> data-fallback="<?= e(__('members.wizard_card_name')) ?>"><?= e($name !== '' ? $name : __('members.wizard_card_name')) ?></span>
                <strong class="tessera-number"<?= $hook('data-tessera-number') ?>>#<?= e($number !== '' ? $number : '—') ?></strong>
                <span class="tessera-type"<?= $hook('data-tessera-type') ?> data-role="<?= e($role) ?>"><strong><?= e(trim($role . ' ' . $type)) ?></strong></span>
            </div>
        </div>
    </div>
</div>
