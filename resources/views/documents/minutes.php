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
 * “Crea nuovo verbale” wizard (5 steps): template, formal data, attendance/quorum,
 * agenda with SOCLY imports, closure + PDF.
 *
 * @var list<array{key:string,label:string,description:string,category:string,mode:string}> $templates
 * @var array<int,string> $people
 * @var list<array{id:int,name:string,role:string}> $board_members
 * @var list<array{id:int,label:string,number:string}> $members_list
 * @var array<string,string> $numbers
 * @var string $default_date
 * @var string $default_number
 * @var string $default_place
 * @var string $pending_text
 * @var string $budget_text
 */
$oldArray = static function (string $name): array {
    $value = old($name, []);
    return is_array($value) ? array_values($value) : [];
};
$agendaTitles = $oldArray('agenda_title');
if ($agendaTitles === []) {
    $agendaTitles = [''];
}
$agendaTexts = $oldArray('agenda_text');
$agendaOutcomes = $oldArray('agenda_outcome');
$agendaYes = $oldArray('agenda_yes');
$agendaNo = $oldArray('agenda_no');
$agendaAbstain = $oldArray('agenda_abstain');
$defaultType = (string) old('minutes_type', (string) ($templates[0]['key'] ?? 'board'));
$templateModes = [];
$templateDefaults = [];
foreach ($templates as $template) {
    $key = (string) $template['key'];
    $templateModes[$key] = (string) $template['mode'];
    $templateDefaults[$key] = match ($key) {
        'board' => [
            (string) __('documents.minutes_agenda_default_board_1'),
            (string) __('documents.minutes_agenda_default_board_2'),
            (string) __('documents.minutes_agenda_default_board_3'),
        ],
        'assembly_ordinary' => [
            (string) __('documents.minutes_agenda_default_ordinary_1'),
            (string) __('documents.minutes_agenda_default_ordinary_2'),
            (string) __('documents.minutes_agenda_default_ordinary_3'),
        ],
        'assembly_extraordinary' => [
            (string) __('documents.minutes_agenda_default_extra_1'),
            (string) __('documents.minutes_agenda_default_extra_2'),
        ],
        'convocation' => [
            (string) __('documents.minutes_agenda_default_ordinary_1'),
            (string) __('documents.minutes_agenda_default_ordinary_2'),
        ],
        default => [],
    };
}
$attendance = old('attendance', []);
$memberPresence = old('member_presence', []);
$memberDelegates = old('member_delegate_to', []);
$attendance = is_array($attendance) ? $attendance : [];
$memberPresence = is_array($memberPresence) ? $memberPresence : [];
$memberDelegates = is_array($memberDelegates) ? $memberDelegates : [];
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e((string) ($title ?? __('documents.minutes_wizard_title'))) ?></h1>
        <p class="page-lede"><?= e(__('documents.minutes_wizard_lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/documents')) ?>"><?= e(__('common.back')) ?></a>
    </div>
</div>

<form
    class="form-card member-wizard minutes-wizard"
    method="post"
    action="<?= e(url('/documents/minutes')) ?>"
    data-minutes-wizard
    data-total-steps="5"
    autocomplete="off"
>
    <?= csrf_field() ?>
    <script type="application/json" data-minutes-numbers><?= json_encode($numbers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script type="application/json" data-minutes-template-modes><?= json_encode($templateModes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script type="application/json" data-minutes-template-defaults><?= json_encode($templateDefaults, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

    <ol class="wizard-steps" aria-label="<?= e(__('documents.minutes_wizard_nav')) ?>">
        <?php foreach ([
            1 => __('documents.minutes_step_type'),
            2 => __('documents.minutes_step_data'),
            3 => __('documents.minutes_step_people'),
            4 => __('documents.minutes_step_agenda'),
            5 => __('documents.minutes_step_close'),
        ] as $stepNumber => $stepTitle): ?>
            <li class="wizard-step<?= $stepNumber === 1 ? ' is-active' : '' ?>" data-minutes-step="<?= (int) $stepNumber ?>" aria-label="<?= e($stepTitle) ?>" title="<?= e($stepTitle) ?>">
                <span class="wizard-index"><?= (int) $stepNumber ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
    <div class="wizard-progress" aria-hidden="true"><span data-minutes-progress style="width:20%"></span></div>

    <section class="wizard-panel is-active" data-minutes-panel="1">
        <div class="wizard-panel-head">
            <h2 class="section-title" tabindex="-1"><?= e(__('documents.minutes_step_type')) ?></h2>
            <p class="section-lede"><?= e(__('documents.minutes_step_type_lede')) ?></p>
        </div>
        <div class="wizard-panel-body">
            <div class="wizard-panel-main">
                <?= field('radio_cards', [
                    'name' => 'minutes_type',
                    'label' => (string) __('documents.minutes_type'),
                    'value' => $defaultType,
                    'required' => true,
                    'options' => array_map(static fn (array $template): array => [
                        'value' => (string) $template['key'],
                        'label' => (string) $template['label'],
                        'description' => (string) $template['description'],
                    ], $templates),
                ]) ?>
            </div>
            <aside class="wizard-panel-side wizard-panel-side--spacer" aria-hidden="true"></aside>
        </div>
    </section>

    <section class="wizard-panel" data-minutes-panel="2">
        <div class="wizard-panel-head">
            <h2 class="section-title" tabindex="-1"><?= e(__('documents.minutes_step_data')) ?></h2>
            <p class="section-lede"><?= e(__('documents.minutes_step_data_lede')) ?></p>
        </div>
        <div class="wizard-panel-body">
            <div class="wizard-panel-main">
                <div class="setup-equal-row">
                    <?= field('text', [
                        'name' => 'minutes_number',
                        'label' => (string) __('documents.minutes_field_number'),
                        'hint' => (string) __('documents.minutes_field_number_hint'),
                        'value' => (string) old('minutes_number', $default_number ?? ''),
                        'required' => true,
                        'maxlength' => 40,
                        'context' => 'member',
                        'attrs' => ['data-minutes-number' => true],
                    ]) ?>
                    <?= field('date', [
                        'name' => 'minutes_date',
                        'label' => (string) __('documents.minutes_field_date'),
                        'value' => (string) ($default_date ?? date('Y-m-d')),
                        'required' => true,
                        'context' => 'member',
                    ]) ?>
                </div>
                <div class="setup-equal-row">
                    <?= field('text', [
                        'name' => 'minutes_time_open',
                        'label' => (string) __('documents.minutes_field_time_open'),
                        'value' => (string) old('minutes_time_open', ''),
                        'input_type' => 'time',
                        'context' => 'member',
                    ]) ?>
                    <?= field('date', [
                        'name' => 'minutes_convocation_date',
                        'label' => (string) __('documents.minutes_field_convocation_date'),
                        'value' => (string) old('minutes_convocation_date', ''),
                        'context' => 'member',
                    ]) ?>
                </div>
                <?= field('radio_cards', [
                    'name' => 'minutes_place_mode',
                    'label' => (string) __('documents.minutes_field_place_mode'),
                    'value' => (string) old('minutes_place_mode', 'in_person'),
                    'required' => true,
                    'options' => [
                        ['value' => 'in_person', 'label' => (string) __('documents.minutes_place_in_person')],
                        ['value' => 'video', 'label' => (string) __('documents.minutes_place_video')],
                    ],
                ]) ?>
                <div data-minutes-place-block="in_person">
                    <?= field('text', [
                        'name' => 'minutes_place',
                        'label' => (string) __('documents.minutes_field_place'),
                        'hint' => (string) __('documents.minutes_field_place_hint'),
                        'value' => (string) old('minutes_place', $default_place ?? ''),
                        'maxlength' => 190,
                        'context' => 'member',
                    ]) ?>
                </div>
                <div data-minutes-place-block="video" hidden>
                    <?= field('text', [
                        'name' => 'minutes_video_link',
                        'label' => (string) __('documents.minutes_field_video'),
                        'value' => (string) old('minutes_video_link', ''),
                        'input_type' => 'url',
                        'maxlength' => 255,
                        'context' => 'member',
                        'placeholder' => 'https://…',
                    ]) ?>
                </div>
                <div class="setup-equal-row">
                    <?= field('checkbox', [
                        'name' => 'minutes_convoked',
                        'label' => (string) __('documents.minutes_field_convoked'),
                        'value' => '1',
                        'checked' => (bool) old('minutes_convoked', 0),
                        'context' => 'member',
                        'label_class' => 'checkbox-row setup-check',
                    ]) ?>
                </div>
            </div>
            <aside class="wizard-panel-side wizard-panel-side--spacer" aria-hidden="true"></aside>
        </div>
    </section>


    <section class="wizard-panel" data-minutes-panel="3">
        <div class="wizard-panel-head">
            <h2 class="section-title" tabindex="-1"><?= e(__('documents.minutes_step_people')) ?></h2>
            <p class="section-lede"><?= e(__('documents.minutes_step_people_lede')) ?></p>
        </div>
        <div class="wizard-panel-body">
            <div class="wizard-panel-main">
                <div class="setup-equal-row">
                    <?= field('select', [
                        'name' => 'president_id',
                        'label' => (string) __('documents.minutes_field_president'),
                        'value' => (string) old('president_id', ''),
                        'include_empty' => true,
                        'empty_label' => (string) __('documents.minutes_field_choose'),
                        'options' => $people,
                        'context' => 'member',
                    ]) ?>
                    <?= field('text', [
                        'name' => 'president_substitute',
                        'label' => (string) __('documents.minutes_field_president_substitute'),
                        'value' => (string) old('president_substitute', ''),
                        'maxlength' => 120,
                        'context' => 'member',
                    ]) ?>
                </div>
                <div class="setup-equal-row">
                    <?= field('select', [
                        'name' => 'secretary_id',
                        'label' => (string) __('documents.minutes_field_secretary'),
                        'value' => (string) old('secretary_id', ''),
                        'include_empty' => true,
                        'empty_label' => (string) __('documents.minutes_field_choose'),
                        'options' => $people,
                        'context' => 'member',
                    ]) ?>
                    <?= field('text', [
                        'name' => 'secretary_substitute',
                        'label' => (string) __('documents.minutes_field_secretary_substitute'),
                        'value' => (string) old('secretary_substitute', ''),
                        'maxlength' => 120,
                        'context' => 'member',
                    ]) ?>
                </div>

                <div data-minutes-attendance-scope="board">
                    <h3 class="member-profile-block-title"><?= e(__('documents.minutes_board_attendance')) ?></h3>
                    <div data-minutes-attendance="board">
                        <div class="table-wrap embedded">
                            <table class="minutes-attendance-table">
                                <thead>
                                    <tr>
                                        <th><?= e(__('documents.minutes_attendee')) ?></th>
                                        <th><?= e(__('documents.minutes_attendance_column')) ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if ($board_members === []): ?>
                                    <tr><td colspan="2" class="muted"><?= e(__('documents.minutes_board_empty')) ?></td></tr>
                                <?php endif; ?>
                                <?php foreach ($board_members as $person): ?>
                                    <?php $personId = (int) $person['id']; ?>
                                    <tr data-minutes-attendee>
                                        <td>
                                            <strong><?= e((string) $person['name']) ?></strong>
                                            <span class="muted"><?= e((string) $person['role']) ?></span>
                                        </td>
                                        <td>
                                            <select class="sf-field__control" name="attendance[<?= $personId ?>]" aria-label="<?= e((string) $person['name']) ?>">
                                                <?php foreach (['present', 'absent_justified', 'absent'] as $state): ?>
                                                    <option value="<?= e($state) ?>"<?= (string) ($attendance[$personId] ?? 'present') === $state ? ' selected' : '' ?>><?= e(__('documents.minutes_attendance_' . $state)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="minutes-quorum-line">
                            <span><?= e(__('documents.minutes_present_label')) ?> <strong data-minutes-present-count>0</strong></span>
                            <span><?= e(__('documents.minutes_total_label')) ?> <strong data-minutes-total-count>0</strong></span>
                            <span class="minutes-quorum-badge" data-minutes-quorum-state
                                data-label-ok="<?= e(__('documents.minutes_quorum_reached')) ?>"
                                data-label-missing="<?= e(__('documents.minutes_quorum_missing')) ?>"></span>
                        </p>
                    </div>
                </div>


                <div data-minutes-attendance-scope="assembly" hidden>
                    <h3 class="member-profile-block-title"><?= e(__('documents.minutes_assembly_attendance')) ?></h3>
                    <?= field('number', [
                        'name' => 'delegation_limit',
                        'label' => (string) __('documents.minutes_delegation_limit'),
                        'hint' => (string) __('documents.minutes_delegation_limit_hint'),
                        'value' => (string) old('delegation_limit', '3'),
                        'min' => 0,
                        'max' => 99,
                        'context' => 'member',
                    ]) ?>
                    <?= field('search', [
                        'name' => 'minutes_member_search',
                        'label' => (string) __('documents.minutes_member_search'),
                        'value' => '',
                        'context' => 'member',
                        'placeholder' => (string) __('documents.minutes_member_search_placeholder'),
                        'attrs' => ['data-minutes-member-search' => true, 'autocomplete' => 'off'],
                    ]) ?>
                    <div data-minutes-attendance="assembly">
                        <div class="table-wrap embedded minutes-attendance-scroll">
                            <table class="minutes-attendance-table">
                                <thead>
                                    <tr>
                                        <th><?= e(__('documents.minutes_attendee')) ?></th>
                                        <th><?= e(__('documents.minutes_attendance_column')) ?></th>
                                        <th><?= e(__('documents.minutes_delegate_to')) ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if ($members_list === []): ?>
                                    <tr><td colspan="3" class="muted"><?= e(__('documents.minutes_members_empty')) ?></td></tr>
                                <?php endif; ?>
                                <?php foreach ($members_list as $member): ?>
                                    <?php
                                    $memberId = (int) $member['id'];
                                    $presence = (string) ($memberPresence[$memberId] ?? 'absent');
                                    ?>
                                    <tr data-minutes-attendee data-minutes-member-row data-search="<?= e(mb_strtolower((string) $member['label'])) ?>">
                                        <td>
                                            <strong><?= e((string) $member['label']) ?></strong>
                                            <span class="muted"><?= e((string) $member['number']) ?></span>
                                        </td>
                                        <td>
                                            <select class="sf-field__control" name="member_presence[<?= $memberId ?>]" aria-label="<?= e((string) $member['label']) ?>">
                                                <?php foreach (['present', 'delegate', 'absent'] as $state): ?>
                                                    <option value="<?= e($state) ?>"<?= $presence === $state ? ' selected' : '' ?>><?= e(__('documents.minutes_presence_' . $state)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input class="sf-field__control" type="text" name="member_delegate_to[<?= $memberId ?>]" value="<?= e((string) ($memberDelegates[$memberId] ?? '')) ?>" maxlength="120" aria-label="<?= e(__('documents.minutes_delegate_to')) ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="minutes-quorum-line">
                            <span><?= e(__('documents.minutes_present_label')) ?> <strong data-minutes-present-count>0</strong></span>
                            <span><?= e(__('documents.minutes_delegations_label')) ?> <strong data-minutes-delegation-count>0</strong></span>
                            <span><?= e(__('documents.minutes_total_label')) ?> <strong data-minutes-total-count>0</strong></span>
                            <span class="minutes-quorum-badge" data-minutes-quorum-state
                                data-label-ok="<?= e(__('documents.minutes_quorum_reached')) ?>"
                                data-label-missing="<?= e(__('documents.minutes_quorum_missing')) ?>"></span>
                        </p>
                        <p class="alert alert-warn" data-minutes-delegation-warning hidden><?= e(__('documents.minutes_delegation_warning')) ?></p>
                    </div>
                </div>
            </div>
            <aside class="wizard-panel-side wizard-panel-side--spacer" aria-hidden="true"></aside>
        </div>
    </section>


    <section class="wizard-panel" data-minutes-panel="4">
        <div class="wizard-panel-head">
            <h2 class="section-title" tabindex="-1"><?= e(__('documents.minutes_step_agenda')) ?></h2>
            <p class="section-lede"><?= e(__('documents.minutes_step_agenda_lede')) ?></p>
        </div>
        <div class="wizard-panel-body">
            <div class="wizard-panel-main">
                <div class="minutes-agenda-tools">
                    <button type="button" class="btn btn-ghost btn-sm" data-minutes-agenda-add><?= e(__('documents.minutes_agenda_add')) ?></button>
                    <?php if (($pending_text ?? '') !== ''): ?>
                        <button type="button" class="btn btn-ghost btn-sm" data-minutes-insert="pending" data-minutes-pending-text="<?= e((string) $pending_text) ?>"><?= e(__('documents.minutes_insert_pending')) ?></button>
                    <?php endif; ?>
                    <?php if (($budget_text ?? '') !== ''): ?>
                        <button type="button" class="btn btn-ghost btn-sm" data-minutes-insert="budget" data-minutes-budget-text="<?= e((string) $budget_text) ?>"><?= e(__('documents.minutes_insert_budget')) ?></button>
                    <?php endif; ?>
                </div>
                <div class="minutes-agenda-list" data-minutes-agenda-list>
                    <?php foreach ($agendaTitles as $index => $agendaTitle): ?>
                        <?= view_partial('documents/_minutes_agenda_item', [
                            'index' => $index + 1,
                            'title' => (string) $agendaTitle,
                            'text' => (string) ($agendaTexts[$index] ?? ''),
                            'outcome' => (string) ($agendaOutcomes[$index] ?? 'majority'),
                            'yes' => (int) ($agendaYes[$index] ?? 0),
                            'no' => (int) ($agendaNo[$index] ?? 0),
                            'abstain' => (int) ($agendaAbstain[$index] ?? 0),
                            'required' => $index === 0,
                        ]) ?>
                    <?php endforeach; ?>
                </div>
                <template data-minutes-agenda-template>
                    <?= view_partial('documents/_minutes_agenda_item', [
                        'index' => 1,
                        'title' => '',
                        'text' => '',
                        'outcome' => 'majority',
                        'yes' => 0,
                        'no' => 0,
                        'abstain' => 0,
                        'required' => false,
                    ]) ?>
                </template>
            </div>
            <aside class="wizard-panel-side wizard-panel-side--spacer" aria-hidden="true"></aside>
        </div>
    </section>

    <section class="wizard-panel" data-minutes-panel="5">
        <div class="wizard-panel-head">
            <h2 class="section-title" tabindex="-1"><?= e(__('documents.minutes_step_close')) ?></h2>
            <p class="section-lede"><?= e(__('documents.minutes_step_close_lede')) ?></p>
        </div>
        <div class="wizard-panel-body">
            <div class="wizard-panel-main">
                <?= field('text', [
                    'name' => 'minutes_time_close',
                    'label' => (string) __('documents.minutes_field_time_close'),
                    'value' => (string) old('minutes_time_close', ''),
                    'input_type' => 'time',
                    'context' => 'member',
                ]) ?>
                <?= field('textarea', [
                    'name' => 'minutes_notes',
                    'label' => (string) __('documents.minutes_field_notes'),
                    'value' => (string) old('minutes_notes', ''),
                    'rows' => 3,
                    'context' => 'member',
                ]) ?>
                <?= field('radio_cards', [
                    'name' => 'minutes_status',
                    'label' => (string) __('documents.minutes_field_status'),
                    'value' => (string) old('minutes_status', 'draft'),
                    'required' => true,
                    'options' => [
                        [
                            'value' => 'draft',
                            'label' => (string) __('documents.minutes_state_draft'),
                            'description' => (string) __('documents.minutes_state_draft_lede'),
                        ],
                        [
                            'value' => 'approved',
                            'label' => (string) __('documents.minutes_state_final'),
                            'description' => (string) __('documents.minutes_state_final_lede'),
                        ],
                    ],
                ]) ?>
                <p class="setup-hint muted"><?= e(__('documents.minutes_pdf_hint')) ?></p>
            </div>
            <aside class="wizard-panel-side wizard-panel-side--spacer" aria-hidden="true"></aside>
        </div>
    </section>

    <div class="wizard-actions">
        <button type="button" class="btn btn-ghost" data-minutes-prev hidden><?= e(__('members.wizard_prev')) ?></button>
        <div class="wizard-actions-right">
            <button type="button" class="btn" data-minutes-next><?= e(__('members.wizard_next')) ?></button>
            <button type="submit" class="btn" data-minutes-submit><?= e(__('documents.minutes_create')) ?></button>
        </div>
    </div>
    <p class="wizard-error muted" data-minutes-error hidden><?= e(__('documents.minutes_wizard_incomplete')) ?></p>
</form>
