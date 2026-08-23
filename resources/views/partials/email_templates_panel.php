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
            <input type="hidden" name="action" value="save" data-tpl-action-field>
            <input type="hidden" name="id" value="<?= $editId ?>">
            <div class="setup-equal-row">
                <label class="setup-field">
                    <span><?= e(__('email_templates.name')) ?> *</span>
                    <input type="text" name="name" required maxlength="120" value="<?= e((string) old('name', $editing['name'] ?? '')) ?>" data-tpl-name>
                </label>
                <label class="setup-field">
                    <span><?= e(__('email_templates.slug')) ?> *</span>
                    <input type="text" name="slug" required maxlength="80" pattern="[a-z0-9\-]+" value="<?= e((string) old('slug', $editing['slug'] ?? '')) ?>" data-tpl-slug <?= $isSystemSlug ? 'readonly' : '' ?>>
                </label>
            </div>
            <label class="setup-field">
                <span><?= e(__('email_templates.body_format')) ?></span>
                <select name="body_format" data-tpl-body-format>
                    <option value="text" <?= $bodyFormat === 'text' ? 'selected' : '' ?>><?= e(__('email_templates.format_text')) ?></option>
                    <option value="html" <?= $bodyFormat === 'html' ? 'selected' : '' ?>><?= e(__('email_templates.format_html')) ?></option>
                </select>
            </label>
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
                    <label class="setup-field">
                        <span><?= e(__('email_templates.subject')) ?> (<?= strtoupper($code) ?>)</span>
                        <input type="text" name="subject<?= $suf ?>" maxlength="250" value="<?= e((string) old('subject' . $suf, $editing['subject' . $suf] ?? '')) ?>" data-tpl-subject="<?= e($code) ?>"<?= $req ?>>
                    </label>
                    <label class="setup-field">
                        <span><?= e(__('email_templates.body')) ?> (<?= strtoupper($code) ?>)</span>
                        <textarea name="body<?= $suf ?>" rows="10" class="tpl-body-text" data-tpl-body="<?= e($code) ?>"<?= $req ?>><?= e((string) old('body' . $suf, $editing['body' . $suf] ?? '')) ?></textarea>
                        <div class="tpl-body-html-wrap" data-tpl-html-wrap="<?= e($code) ?>" hidden>
                            <div class="tpl-html-mode-tabs">
                                <button type="button" class="tpl-html-mode-tab is-active" data-html-mode="source" data-lang="<?= e($code) ?>"><?= e(__('email_templates.html_source')) ?></button>
                                <button type="button" class="tpl-html-mode-tab" data-html-mode="preview" data-lang="<?= e($code) ?>"><?= e(__('email_templates.html_preview')) ?></button>
                            </div>
                            <textarea class="tpl-body-source" data-tpl-body-source="<?= e($code) ?>" rows="12" spellcheck="false"></textarea>
                            <div class="mail-preview-html tpl-inline-preview" data-tpl-inline-preview="<?= e($code) ?>" hidden></div>
                        </div>
                    </label>
                </div>
            <?php endforeach; ?>
            <div class="email-template-test-box">
                <h4 class="setup-subhead"><?= e(__('email_templates.test_title')) ?></h4>
                <p class="setup-hint muted"><?= e(__('email_templates.test_hint')) ?></p>
                <div class="setup-equal-row">
                    <label class="setup-field">
                        <span><?= e(__('email_templates.test_email')) ?></span>
                        <input type="email" name="test_email" maxlength="190" value="<?= e((string) old('test_email', $defaultTestEmail ?? '')) ?>" data-tpl-test-email>
                    </label>
                    <label class="setup-field">
                        <span><?= e(__('email_templates.test_lang')) ?></span>
                        <select name="test_lang" data-tpl-test-lang>
                            <option value="it">IT</option>
                            <option value="en">EN</option>
                            <option value="de">DE</option>
                        </select>
                    </label>
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
                            <td>
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('/settings?edit_template=' . (int) ($t['id'] ?? 0) . '#email-templates')) ?>"><?= e(__('common.edit')) ?></a>
                                <?php if (!$isSystem): ?>
                                    <form method="post" action="<?= e(url('/settings/email-templates')) ?>" class="inline-form" data-confirm="<?= e(__('email_templates.confirm_delete')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) ($t['id'] ?? 0) ?>">
                                        <button class="btn btn-danger btn-sm" type="submit"><?= e(__('common.delete')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
