<?php
/**
 * @var list<array<string,mixed>> $workflowRules
 * @var list<array<string,mixed>> $emailTemplates
 * @var array<string,mixed>|null $editingWorkflow
 */
$workflowRules = is_array($workflowRules ?? null) ? $workflowRules : [];
$emailTemplates = is_array($emailTemplates ?? null) ? $emailTemplates : [];
$editing = is_array($editingWorkflow ?? null) ? $editingWorkflow : null;
$eventGroups = \Socly\Services\WorkflowService::eventGroups();
$events = \Socly\Services\WorkflowService::events();
?>
<p class="setup-hint muted"><?= e(__('workflow.intro')) ?></p>

<div class="setup-membership-card workflow-rule-form-card">
    <h3 class="setup-subhead"><?= e($editing ? __('workflow.edit') : __('workflow.create')) ?></h3>
    <form method="post" action="<?= e(url('/settings/workflow')) ?>" class="workflow-rule-form" data-leave-guard>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
        <label class="setup-field">
            <span><?= e(__('workflow.name')) ?> *</span>
            <input type="text" name="name" required maxlength="160" value="<?= e((string) old('name', $editing['name'] ?? '')) ?>">
        </label>
        <label class="setup-field">
            <span><?= e(__('workflow.event')) ?> *</span>
            <select name="event_key" required>
                <?php foreach ($eventGroups as $groupKey => $groupEvents): ?>
                    <optgroup label="<?= e(__($groupKey)) ?>">
                        <?php foreach ($groupEvents as $k => $labelKey): ?>
                            <option value="<?= e($k) ?>" <?= (string) old('event_key', $editing['event_key'] ?? '') === $k ? 'selected' : '' ?>><?= e(__($labelKey)) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="setup-equal-row">
            <label class="setup-field">
                <span><?= e(__('workflow.template')) ?> *</span>
                <select name="template_slug" required>
                    <option value="">—</option>
                    <?php foreach ($emailTemplates as $t): ?>
                        <option value="<?= e((string) ($t['slug'] ?? '')) ?>" <?= (string) old('template_slug', $editing['template_slug'] ?? '') === (string) ($t['slug'] ?? '') ? 'selected' : '' ?>>
                            <?= e((string) ($t['name'] ?? '')) ?> (<?= e((string) ($t['slug'] ?? '')) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="setup-field">
                <span><?= e(__('workflow.delay')) ?></span>
                <input type="number" name="delay_minutes" min="0" max="10080" value="<?= (int) old('delay_minutes', $editing['delay_minutes'] ?? 0) ?>">
                <span class="setup-hint muted"><?= e(__('workflow.delay_hint')) ?></span>
            </label>
        </div>
        <label class="setup-check setup-check-prominent">
            <input type="checkbox" name="enabled" value="1" <?= old('enabled', $editing['enabled'] ?? 1) ? 'checked' : '' ?>>
            <span><?= e(__('workflow.enabled')) ?></span>
        </label>
        <div class="actions form-actions form-actions-end">
            <button class="btn" type="submit"><?= e(__('common.save')) ?></button>
            <?php if ($editing): ?>
                <a class="btn btn-ghost" href="<?= e(url('/settings#workflow')) ?>"><?= e(__('workflow.new')) ?></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="settings-workflow-list">
    <h3 class="setup-subhead"><?= e(__('workflow.list_title')) ?></h3>
    <?php if ($workflowRules === []): ?>
        <p class="setup-hint muted"><?= e(__('workflow.none')) ?></p>
    <?php else: ?>
        <div class="table-wrap embedded">
            <table>
                <thead>
                    <tr>
                        <th><?= e(__('workflow.name')) ?></th>
                        <th><?= e(__('workflow.event')) ?></th>
                        <th><?= e(__('workflow.template')) ?></th>
                        <th><?= e(__('workflow.status')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($workflowRules as $r): ?>
                        <tr>
                            <td><strong><?= e((string) ($r['name'] ?? '')) ?></strong></td>
                            <td><?= e(__($events[(string) ($r['event_key'] ?? '')] ?? (string) ($r['event_key'] ?? ''))) ?></td>
                            <td><code><?= e((string) ($r['template_slug'] ?? '')) ?></code></td>
                            <td>
                                <?php if (!empty($r['enabled'])): ?>
                                    <span class="badge badge-success"><?= e(__('workflow.active')) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-muted"><?= e(__('workflow.inactive')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="btn btn-ghost btn-sm" href="<?= e(url('/settings?edit_workflow=' . (int) ($r['id'] ?? 0) . '#workflow')) ?>"><?= e(__('common.edit')) ?></a>
                                <form method="post" action="<?= e(url('/settings/workflow')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) ($r['id'] ?? 0) ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit"><?= !empty($r['enabled']) ? e(__('workflow.deactivate')) : e(__('workflow.activate')) ?></button>
                                </form>
                                <form method="post" action="<?= e(url('/settings/workflow')) ?>" class="inline-form" data-confirm="<?= e(__('workflow.confirm_delete')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) ($r['id'] ?? 0) ?>">
                                    <button class="btn btn-danger btn-sm" type="submit"><?= e(__('common.delete')) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
