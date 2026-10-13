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
        <?= field('hidden', ['name' => 'action', 'value' => 'save']) ?>
        <?= field('hidden', ['name' => 'id', 'value' => (string) (int) ($editing['id'] ?? 0)]) ?>
        <?= field('text', [
            'name' => 'name',
            'label' => (string) __('workflow.name'),
            'value' => (string) old('name', $editing['name'] ?? ''),
            'required' => true,
            'context' => 'setup',
            'maxlength' => 160,
        ]) ?>
        <?php
        $eventOptionGroups = [];
        foreach ($eventGroups as $groupKey => $groupEvents) {
            $opts = [];
            foreach ($groupEvents as $k => $labelKey) {
                $opts[] = ['value' => (string) $k, 'label' => (string) __($labelKey)];
            }
            $eventOptionGroups[] = ['label' => (string) __($groupKey), 'options' => $opts];
        }
        ?>
        <?= field('select', [
            'name' => 'event_key',
            'label' => (string) __('workflow.event'),
            'value' => (string) old('event_key', $editing['event_key'] ?? ''),
            'required' => true,
            'context' => 'setup',
            'include_empty' => false,
            'groups' => $eventOptionGroups,
        ]) ?>
        <div class="setup-equal-row">
            <?php
            $templateOptions = [];
            foreach ($emailTemplates as $t) {
                $slug = (string) ($t['slug'] ?? '');
                $templateOptions[] = [
                    'value' => $slug,
                    'label' => (string) ($t['name'] ?? '') . ' (' . $slug . ')',
                ];
            }
            ?>
            <?= field('select', [
                'name' => 'template_slug',
                'label' => (string) __('workflow.template'),
                'value' => (string) old('template_slug', $editing['template_slug'] ?? ''),
                'required' => true,
                'context' => 'setup',
                'options' => $templateOptions,
            ]) ?>
            <?= field('number', [
                'name' => 'delay_minutes',
                'label' => (string) __('workflow.delay'),
                'value' => (string) (int) old('delay_minutes', $editing['delay_minutes'] ?? 0),
                'context' => 'setup',
                'min' => 0,
                'max' => 10080,
                'hint' => (string) __('workflow.delay_hint'),
            ]) ?>
        </div>
        <?= field('checkbox', [
            'name' => 'enabled',
            'label' => (string) __('workflow.enabled'),
            'checked' => (bool) old('enabled', $editing['enabled'] ?? 1),
            'context' => 'setup',
            'label_class' => 'checkbox-row setup-check setup-check-prominent',
        ]) ?>
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
                            <td class="doc-row-actions">
                                <?= row_actions([
                                    [
                                        'icon' => 'edit',
                                        'label' => __('common.edit'),
                                        'href' => url('/settings?edit_workflow=' . (int) ($r['id'] ?? 0) . '#workflow'),
                                    ],
                                    [
                                        'icon' => 'toggle',
                                        'label' => !empty($r['enabled']) ? __('workflow.deactivate') : __('workflow.activate'),
                                        'form_action' => url('/settings/workflow'),
                                        'hidden' => [
                                            'action' => 'toggle',
                                            'id' => (int) ($r['id'] ?? 0),
                                        ],
                                    ],
                                    [
                                        'icon' => 'delete',
                                        'label' => __('common.delete'),
                                        'danger' => true,
                                        'form_action' => url('/settings/workflow'),
                                        'form_attrs' => [
                                            'data-confirm' => __('workflow.confirm_delete'),
                                        ],
                                        'hidden' => [
                                            'action' => 'delete',
                                            'id' => (int) ($r['id'] ?? 0),
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
