<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
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
 * Member photo upload / camera / remove.
 *
 * @var bool $has_existing
 * @var string $existing_url
 * @var string $gender m|f
 */
use Socly\Support\Fields;

$key = $data_field !== '' ? $data_field : 'photo';
$hasExisting = !empty($has_existing);
$existingUrl = (string) ($existing_url ?? '');
$gender = (string) ($gender ?? 'm');
if (!in_array($gender, ['m', 'f'], true)) {
    $gender = 'm';
}
$uploadLabel = (string) ($upload_label ?? __('members.photo_upload'));
$removeLabel = (string) ($remove_label ?? __('members.photo_remove'));
$captureLabel = (string) ($capture_label ?? __('members.photo_capture'));
$hintText = (string) ($hint !== '' ? $hint : __('members.photo_hint'));
$hintUploadOnly = (string) ($hint_upload_only ?? __('members.photo_hint_upload_only'));
$accept = (string) ($accept ?? 'image/jpeg,image/png,image/webp');
$maxBytes = (int) ($max_bytes ?? 3145728);

$wrapAttrs = [
    'class' => Fields::wrapperClass($context, trim('photo-field ' . $wrapper_class), 'sf-field--photo'),
    'data-sf-field' => 'photo',
    'data-field' => $key,
    'data-field-required' => $required ? '1' : '0',
    'data-photo-field' => true,
    'data-has-photo' => $hasExisting ? '1' : '0',
];
?>
<div<?= Fields::attrs($wrapAttrs) ?>>
    <div class="photo-upload photo-upload--card">
        <?php if ($hasExisting && $existingUrl !== ''): ?>
            <button type="button" class="photo-preview-btn" data-photo-lightbox-open aria-label="<?= e(__('members.photo_preview_open')) ?>">
                <img class="photo-preview" src="<?= e($existingUrl) ?>" alt="">
            </button>
        <?php else: ?>
            <div class="photo-placeholder" data-photo-placeholder data-photo-gender="<?= e($gender) ?>" aria-hidden="true"></div>
        <?php endif; ?>
        <div class="photo-controls">
            <div class="photo-actions">
                <label class="btn btn-sm btn-ghost file-btn" data-photo-upload-btn<?= $hasExisting ? ' hidden' : '' ?>>
                    <span class="field-icon" data-icon="upload" aria-hidden="true"></span>
                    <?= e($uploadLabel) ?>
                    <input
                        type="file"
                        name="<?= e($name !== '' ? $name : 'fields[photo]') ?>"
                        accept="<?= e($accept) ?>"
                        data-photo-input
                        data-invalid="<?= e(__('validation.photo')) ?>"
                        data-max-bytes="<?= (int) $maxBytes ?>"
                        <?= $required && !$hasExisting ? 'required' : '' ?>
                        hidden
                    >
                </label>
                <button type="button" class="btn btn-sm btn-ghost" data-camera-open<?= $hasExisting ? ' hidden' : '' ?>>
                    <span class="field-icon" data-icon="camera" aria-hidden="true"></span>
                    <?= e($captureLabel) ?>
                </button>
                <button type="button" class="btn btn-sm btn-ghost" data-photo-remove<?= $hasExisting ? '' : ' hidden' ?>>
                    <?= e($removeLabel) ?>
                </button>
            </div>
            <p
                class="muted"
                data-photo-hint
                data-upload-only="<?= e($hintUploadOnly) ?>"
                data-invalid="<?= e(__('validation.photo')) ?>"
            ><?= e($hintText) ?></p>
            <p
                class="field-hint"
                data-camera-error
                data-unavailable="<?= e(__('members.photo_camera_unavailable')) ?>"
                data-not-ready="<?= e(__('members.photo_camera_not_ready')) ?>"
                hidden
            ></p>
            <input type="hidden" name="remove_photo" value="" data-photo-remove-flag>
            <input type="file" accept="image/*" capture="user" data-camera-capture hidden>
        </div>
    </div>
</div>
