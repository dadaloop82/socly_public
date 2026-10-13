<?php
/**
 * @var list<array<string,mixed>> $emailTemplates
 * @var array<string,mixed>|null $editingTemplate
 * @var string $emailSampleJson
 * @var bool $mailReady
 * @var string $defaultTestEmail
 */
$emailTemplates = is_array($emailTemplates ?? null) ? $emailTemplates : [];
$editing = is_array($editingTemplate ?? null) ? $editingTemplate : null;
$editId = (int) ($editing['id'] ?? 0);
$tplService = app(\Socly\Services\EmailTemplateService::class);
$bodyFormat = $tplService->normalizeBodyFormat((string) ($editing['body_format'] ?? 'text'));
$placeholders = \Socly\Services\EmailTemplateService::placeholderCatalog();
$conditionals = \Socly\Services\EmailTemplateService::conditionalCatalog();
$systemSlugs = \Socly\Services\EmailTemplateService::SYSTEM_SLUGS;
$isSystemSlug = $editing && in_array((string) ($editing['slug'] ?? ''), $systemSlugs, true);
?>
<p class="setup-hint muted"><?= e(__('email_templates.intro')) ?></p>
<div class="email-template-placeholders">
    <p class="setup-subhead"><?= e(__('email_templates.placeholders')) ?></p>
    <div class="email-template-ph-list">
        <?php foreach ($placeholders as $key => $labelKey): ?>
            <button type="button" class="btn btn-ghost btn-sm" data-insert-ph="<?= e($key) ?>" title="<?= e(__($labelKey)) ?>">{{<?= e($key) ?>}}</button>
        <?php endforeach; ?>
    </div>
    <div class="email-template-cond-list">
        <?php foreach ($conditionals as $key => $labelKey): ?>
            <code class="email-template-cond" title="<?= e(__($labelKey)) ?>">{{#<?= e($key) ?>}}…{{/<?= e($key) ?>}}</code>
        <?php endforeach; ?>
    </div>
</div>

<div class="email-template-editor-grid">
    <div class="setup-membership-card">
        <h3 class="setup-subhead"><?= e($editing ? __('email_templates.edit') : __('email_templates.create')) ?></h3>
        <form method="post" action="<?= e(url('/settings/email-templates')) ?>" data-email-template-form data-leave-guard>
            <?= csrf_field() ?>
            <?= field('hidden', ['name' => 'action', 'value' => 'save', 'attrs' => ['data-tpl-action-field' => true]]) ?>
            <?= field('hidden', ['name' => 'id', 'value' => (string) $editId]) ?>
            <div class="setup-equal-row">
                <?= field('text', [
                    'name' => 'name',
                    'label' => (string) __('email_templates.name'),
                    'value' => (string) old('name', $editing['name'] ?? ''),
                    'required' => true,
                    'context' => 'setup',
                    'maxlength' => 120,
                    'attrs' => ['data-tpl-name' => true],
                ]) ?>
                <?= field('text', [
                    'name' => 'slug',
                    'label' => (string) __('email_templates.slug'),
                    'value' => (string) old('slug', $editing['slug'] ?? ''),
                    'required' => true,
                    'context' => 'setup',
                    'maxlength' => 80,
                    'pattern' => '[a-z0-9\\-]+',
                    'readonly' => $isSystemSlug,
                    'attrs' => ['data-tpl-slug' => true],
                ]) ?>
            </div>
            <?= field('select', [
                'name' => 'body_format',
                'label' => (string) __('email_templates.body_format'),
                'value' => $bodyFormat,
                'context' => 'setup',
                'include_empty' => false,
                'options' => [
                    ['value' => 'text', 'label' => (string) __('email_templates.format_text')],
                    ['value' => 'html', 'label' => (string) __('email_templates.format_html')],
                ],
                'attrs' => ['data-tpl-body-format' => true],
            ]) ?>
            <div class="email-template-lang-tabs" role="tablist" data-tpl-lang-tabs>
                <?php foreach (['it' => 'Italiano', 'en' => 'English', 'de' => 'Deutsch'] as $code => $lab): ?>
                    <button type="button" class="email-template-lang-tab<?= $code === 'it' ? ' is-active' : '' ?>" data-lang-tab="<?= e($code) ?>" role="tab"><?= e($lab) ?></button>
                <?php endforeach; ?>
            </div>
            <?php foreach (['it', 'en', 'de'] as $code): ?>
                <?php
                    $suf = $code === 'it' ? '' : ('_' . $code);
                    $req = $code === 'it' ? ' required' : '';
                ?>
                <div class="email-template-lang-pane<?= $code === 'it' ? ' is-active' : '' ?>" data-lang-pane="<?= e($code) ?>">
                    <?= field('text', [
                        'name' => 'subject' . $suf,
                        'label' => (string) __('email_templates.subject') . ' (' . strtoupper($code) . ')',
                        'value' => (string) old('subject' . $suf, $editing['subject' . $suf] ?? ''),
                        'required' => $code === 'it',
                        'context' => 'setup',
                        'maxlength' => 250,
                        'attrs' => ['data-tpl-subject' => $code],
                    ]) ?>
                    <div class="setup-field sf-field">
                        <span class="field-label sf-field__label"><?= e(__('email_templates.body')) ?> (<?= strtoupper($code) ?>)</span>
                        <?= field('textarea', [
                            'name' => 'body' . $suf,
                            'label' => '',
                            'value' => (string) old('body' . $suf, $editing['body' . $suf] ?? ''),
                            'required' => $code === 'it',
                            'context' => 'inline',
                            'rows' => 10,
                            'input_class' => 'tpl-body-text',
                            'attrs' => ['data-tpl-body' => $code],
                        ]) ?>
                        <div class="tpl-body-html-wrap" data-tpl-html-wrap="<?= e($code) ?>" hidden>
                            <div class="tpl-html-mode-tabs">
                                <button type="button" class="tpl-html-mode-tab is-active" data-html-mode="source" data-lang="<?= e($code) ?>"><?= e(__('email_templates.html_source')) ?></button>
                                <button type="button" class="tpl-html-mode-tab" data-html-mode="preview" data-lang="<?= e($code) ?>"><?= e(__('email_templates.html_preview')) ?></button>
                            </div>
                            <?= field('textarea', [
                                'name' => '',
                                'label' => '',
                                'context' => 'inline',
                                'rows' => 12,
                                'input_class' => 'tpl-body-source',
                                'attrs' => [
                                    'data-tpl-body-source' => $code,
                                    'spellcheck' => 'false',
                                ],
                            ]) ?>
                            <div class="mail-preview-html tpl-inline-preview" data-tpl-inline-preview="<?= e($code) ?>" hidden></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="email-template-test-box">
                <h4 class="setup-subhead"><?= e(__('email_templates.test_title')) ?></h4>
                <p class="setup-hint muted"><?= e(__('email_templates.test_hint')) ?></p>
                <div class="setup-equal-row">
                    <?= field('email', [
                        'name' => 'test_email',
                        'label' => (string) __('email_templates.test_email'),
                        'value' => (string) old('test_email', $defaultTestEmail ?? ''),
                        'context' => 'setup',
                        'maxlength' => 190,
                        'attrs' => ['data-tpl-test-email' => true],
                    ]) ?>
                    <?= field('select', [
                        'name' => 'test_lang',
                        'label' => (string) __('email_templates.test_lang'),
                        'value' => (string) old('test_lang', 'it'),
                        'context' => 'setup',
                        'include_empty' => false,
                        'options' => [
                            ['value' => 'it', 'label' => 'IT'],
                            ['value' => 'en', 'label' => 'EN'],
                            ['value' => 'de', 'label' => 'DE'],
                        ],
                        'attrs' => ['data-tpl-test-lang' => true],
                    ]) ?>
                </div>
            </div>
            <div class="actions form-actions form-actions-end">
                <button class="btn" type="submit" data-tpl-submit="save"><?= e(__('common.save')) ?></button>
                <button class="btn btn-ghost" type="submit" data-tpl-submit="send_test" <?= empty($mailReady) ? 'disabled' : '' ?>><?= e(__('email_templates.send_test')) ?></button>
                <?php if ($editing): ?>
                    <a class="btn btn-ghost" href="<?= e(url('/settings#email-templates')) ?>"><?= e(__('email_templates.new')) ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <div class="setup-membership-card email-template-preview-panel" data-email-template-preview data-samples="<?= e($emailSampleJson ?? '{}') ?>">
        <h3 class="setup-subhead"><?= e(__('email_templates.preview_title')) ?></h3>
        <p class="setup-hint muted"><?= e(__('email_templates.preview_hint')) ?></p>
        <div class="mail-preview">
            <div class="mail-preview-meta">
                <div><span class="muted"><?= e(__('email_templates.preview_lang')) ?></span><div data-preview-lang class="mono">IT</div></div>
                <div><span class="muted"><?= e(__('email_templates.preview_format')) ?></span><div data-preview-format class="mono">TESTO</div></div>
                <div><span class="muted"><?= e(__('email_templates.subject')) ?></span><div data-preview-subject class="mail-preview-subject">—</div></div>
            </div>
            <pre data-preview-body-text class="mail-preview-body">—</pre>
            <div data-preview-body-html class="mail-preview-html" hidden></div>
        </div>
    </div>
</div>

<div class="settings-email-templates-list">
    <h3 class="setup-subhead"><?= e(__('email_templates.list_title')) ?></h3>
    <?php if ($emailTemplates === []): ?>
        <p class="setup-hint muted"><?= e(__('email_templates.none')) ?></p>
    <?php else: ?>
        <div class="table-wrap embedded">
            <table>
                <thead>
                    <tr>
                        <th><?= e(__('email_templates.name')) ?></th>
                        <th><?= e(__('email_templates.slug')) ?></th>
                        <th><?= e(__('email_templates.body_format')) ?></th>
                        <th><?= e(__('email_templates.languages')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($emailTemplates as $t): ?>
                        <?php
                            $ready = ['IT'];
                            if (trim((string) ($t['subject_en'] ?? '')) !== '' && trim((string) ($t['body_en'] ?? '')) !== '') {
                                $ready[] = 'EN';
                            }
                            if (trim((string) ($t['subject_de'] ?? '')) !== '' && trim((string) ($t['body_de'] ?? '')) !== '') {
                                $ready[] = 'DE';
                            }
                            $isSystem = !empty($t['is_system']) || in_array((string) ($t['slug'] ?? ''), $systemSlugs, true);
                        ?>
                        <tr>
                            <td>
                                <strong><?= e((string) ($t['name'] ?? '')) ?></strong>
                                <?php if ($isSystem): ?>
                                    <span class="badge badge-muted"><?= e(__('email_templates.system_badge')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><code><?= e((string) ($t['slug'] ?? '')) ?></code></td>
                            <td><?= ($t['body_format'] ?? 'text') === 'html' ? 'HTML' : e(__('email_templates.format_text')) ?></td>
                            <td><?= e(implode(' · ', $ready)) ?></td>
                            <td class="doc-row-actions">
                                <?= row_actions([
                                    [
                                        'icon' => 'edit',
                                        'label' => __('common.edit'),
                                        'href' => url('/settings?edit_template=' . (int) ($t['id'] ?? 0) . '#email-templates'),
                                    ],
                                    [
                                        'icon' => 'delete',
                                        'label' => __('common.delete'),
                                        'show' => !$isSystem,
                                        'danger' => true,
                                        'form_action' => url('/settings/email-templates'),
                                        'form_attrs' => [
                                            'data-confirm' => __('email_templates.confirm_delete'),
                                        ],
                                        'hidden' => [
                                            'action' => 'delete',
                                            'id' => (int) ($t['id'] ?? 0),
                                        ],
                                    ],
                                ]) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
