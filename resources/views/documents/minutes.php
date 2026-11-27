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
 * @var array{years:list<int>,default_year:int,budgets:list<array<string,mixed>>} $budget_voices
 * @var string $default_type preselected wizard model coming from a minutes book card
 * @var array<string,string> $errors
 */
$minutesErrors = [];
$minutesErrorStep = 1;
if (isset($errors) && is_array($errors)) {
    // Field => wizard step, so a server-side rejection reopens the point that needs attention.
    $minutesErrorSteps = [
        'minutes_type' => 1,
        'minutes_number' => 2,
        'minutes_date' => 2,
        'minutes_time_open' => 2,
        'minutes_time_close' => 5,
        'minutes_convocation_date' => 2,
        'minutes_place_mode' => 2,
        'minutes_place' => 2,
        'minutes_video_platform' => 2,
        'minutes_video_link' => 2,
        'president_id' => 3,
        'president_substitute' => 3,
        'secretary_id' => 3,
        'secretary_substitute' => 3,
        'attendance' => 3,
        'member_presence' => 3,
        'member_delegate_to' => 3,
        'delegation_limit' => 3,
        'agenda_title' => 4,
        'agenda_yes' => 4,
        'agenda_no' => 4,
        'agenda_abstain' => 4,
        'minutes_status' => 5,
        'minutes_notes' => 5,
    ];
    foreach ($errors as $field => $message) {
        $text = is_array($message) ? implode(', ', $message) : (string) $message;
        if (trim($text) === '') {
            continue;
        }
        $minutesErrors[] = $text;
        if ($minutesErrorStep === 1 && isset($minutesErrorSteps[(string) $field])) {
            $minutesErrorStep = (int) $minutesErrorSteps[(string) $field];
        }
    }
}
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
// A “Crea verbale” card links here with ?type=<model>: preselect it, but only when it exists.
$requestedType = trim((string) ($default_type ?? ''));
if ($requestedType !== '' && in_array($requestedType, array_column($templates, 'key'), true)) {
    $defaultType = (string) old('minutes_type', $requestedType);
}
$typeIcons = [
    'board' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 21h18"/><path d="M5 21V10M9 21V10M15 21V10M19 21V10"/><path d="m12 3 8 5H4l8-5Z"/></svg>',
    'assembly_ordinary' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
    'assembly_extraordinary' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/></svg>',
    'free' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z"/><path d="M14 3v5h5"/><path d="M8 13h8M8 17h5"/></svg>',
    'convocation' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg>',
];
$platformIcons = [
    'socly' => '<img src="' . e(socly_icon_url()) . '" alt="" width="24" height="24" decoding="async">',
    'teams' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><rect x="2" y="2" width="20" height="20" rx="5" fill="#5059C9"/><path d="M7 8h10M12 8v9" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>',
    'google_meet' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M3 7a2 2 0 0 1 2-2h9v14H5a2 2 0 0 1-2-2z" fill="#00832d"/><path d="M14 10l6-3v10l-6-3z" fill="#0066da"/><path d="M3 17h11" stroke="#ffba00" stroke-width="2"/><path d="M3 7h11" stroke="#e94235" stroke-width="2"/></svg>',
    'zoom' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><rect x="2" y="4" width="20" height="16" rx="5" fill="#2D8CFF"/><rect x="7" y="8" width="8" height="8" rx="2" fill="#fff"/><path d="M15 11l3-2v6l-3-2z" fill="#fff"/></svg>',
    'whatsapp' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M12 2a10 10 0 0 0-8.7 15l-1.3 4.3 4.4-1.3A10 10 0 1 0 12 2Z" fill="#25D366"/><path d="M9 7c.3 0 .6.2.8.6l.8 1.8c.1.3 0 .6-.2.8l-.6.6c.5 1 1.3 1.8 2.3 2.3l.6-.6c.2-.2.5-.3.8-.2l1.8.8c.4.2.6.5.6.8 0 1.2-1 2.1-2.2 2.1-3.3 0-6-2.7-6-6C7.7 8 8.6 7 9 7Z" fill="#fff"/></svg>',
    'jitsi' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><rect x="2" y="4" width="20" height="16" rx="5" fill="#1F3B73"/><rect x="7" y="8" width="8" height="8" rx="2" fill="#fff"/><path d="M15 11l3-2v6l-3-2z" fill="#fff"/></svg>',
    'other' => '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10 13a5 5 0 0 0 7.1 0l2-2a5 5 0 0 0-7.1-7.1L11 5"/><path d="M14 11a5 5 0 0 0-7.1 0l-2 2A5 5 0 0 0 12 20l1-1"/></svg>',
];
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
$convocationNoticeDays = (int) ($convocation_notice_days ?? 0);
// The convocation date is not just “a date”: the statute fixes how many days before the sitting
// it must be, so the field explains the rule instead of failing later with a generic message.
$convocationHint = $convocationNoticeDays > 0
    ? (string) __('documents.minutes_field_convocation_hint', ['days' => (string) $convocationNoticeDays])
    : (string) __('documents.minutes_field_convocation_hint_no_notice');
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
    data-msg-vote-cap="<?= e((string) __('documents.minutes_vote_cap')) ?>"
    data-msg-convocation-after="<?= e((string) __('validation.convocation_after_meeting')) ?>"
    data-msg-convocation-notice="<?= e((string) __('documents.minutes_error_convocation_notice', ['days' => ':days'])) ?>"
    data-msg-convocation-too-old="<?= e((string) __('documents.minutes_error_convocation_too_old')) ?>"
    data-msg-time-order="<?= e((string) __('validation.time_end_before_start')) ?>"
    data-msg-president-required="<?= e((string) __('documents.minutes_error_president_required')) ?>"
    data-msg-secretary-required="<?= e((string) __('documents.minutes_error_secretary_required')) ?>"
    data-convocation-notice-days="<?= (int) ($convocation_notice_days ?? 0) ?>"
    <?php if ($minutesErrors !== []): ?>data-minutes-error-step="<?= (int) $minutesErrorStep ?>"<?php endif; ?>
    autocomplete="off"
    <?php /* The wizard validates every step itself: native validation would block the save with no
             message when a field inside a hidden step is invalid (“Crea nuovo verbale” dead). */ ?>
    novalidate
>
    <?= csrf_field() ?>
    <?php if ($minutesErrors !== []): ?>
        <div class="alert alert-error" role="alert" data-minutes-server-errors>
            <?php foreach ($minutesErrors as $minutesError): ?>
                <div><?= e($minutesError) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <script type="application/json" data-minutes-numbers><?= json_encode($numbers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script type="application/json" data-minutes-budget-data><?= json_encode($budget_voices ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
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
                    'cards_class' => 'minutes-type-cards',
                    'options' => array_map(static fn (array $template): array => [
                        'value' => (string) $template['key'],
                        'label' => (string) $template['label'],
                        'description' => (string) $template['description'],
                        'icon' => $typeIcons[(string) $template['key']] ?? '',
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
                        // No `min`: a minute is normally written after the sitting, with the date
                        // of the meeting. Only an avviso di convocazione must look forward, and the
                        // service says so with a readable message.
                        'context' => 'member',
                    ]) ?>
                </div>
                <div class="setup-equal-row">
                    <?= field('text', [
                        'name' => 'minutes_time_open',
                        'label' => (string) __('documents.minutes_field_time_open'),
                        'value' => (string) old('minutes_time_open', ''),
                        'input_type' => 'time',
                        'required' => true,
                        'context' => 'member',
                    ]) ?>
                    <?= field('date', [
                        'name' => 'minutes_convocation_date',
                        'label' => (string) __('documents.minutes_field_convocation_date'),
                        'hint' => $convocationHint,
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
                    <?= field('radio_cards', [
                        'name' => 'minutes_video_platform',
                        'label' => (string) __('documents.minutes_field_platform'),
                        'hint' => (string) __('documents.minutes_field_platform_hint'),
                        'value' => (string) old('minutes_video_platform', ''),
                        'required' => false,
                        'cards_class' => 'minutes-platform-cards',
                        'options' => [
                            ['value' => 'socly', 'label' => 'Socly', 'icon' => $platformIcons['socly']],
                            ['value' => 'teams', 'label' => 'Microsoft Teams', 'icon' => $platformIcons['teams']],
                            ['value' => 'google_meet', 'label' => 'Google Meet', 'icon' => $platformIcons['google_meet']],
                            ['value' => 'zoom', 'label' => 'Zoom', 'icon' => $platformIcons['zoom']],
                            ['value' => 'whatsapp', 'label' => 'WhatsApp', 'icon' => $platformIcons['whatsapp']],
                            ['value' => 'jitsi', 'label' => 'Jitsi Meet', 'icon' => $platformIcons['jitsi']],
                            ['value' => 'other', 'label' => (string) __('documents.minutes_platform_other'), 'icon' => $platformIcons['other']],
                        ],
                    ]) ?>
                    <?= field('text', [
                        'name' => 'minutes_video_link',
                        'label' => (string) __('documents.minutes_field_video'),
                        'hint' => (string) __('documents.minutes_field_video_hint'),
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
                        'hint' => (string) __('documents.minutes_field_convoked_hint'),
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
                        'hint' => (string) __('documents.minutes_field_leader_hint'),
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
                        'hint' => (string) __('documents.minutes_field_leader_hint'),
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
                                            <strong class="minutes-attendee-name"><?= e((string) $person['name']) ?></strong>
                                            <?php if ((string) $person['role'] !== ''): ?>
                                                <span class="muted minutes-attendee-role"><?= e((string) $person['role']) ?></span>
                                            <?php endif; ?>
                                            <?php
                                            $boardMeta = [];
                                            if ((string) ($person['mandate'] ?? '') !== '') {
                                                $boardMeta[] = (string) __('documents.minutes_mandate_left', [
                                                    'value' => (string) $person['mandate'],
                                                ]);
                                            }
                                            if ((string) ($person['gender'] ?? '') !== '') {
                                                $boardMeta[] = (string) $person['gender'];
                                            }
                                            if ((string) ($person['email'] ?? '') !== '') {
                                                $boardMeta[] = (string) $person['email'];
                                            }
                                            ?>
                                            <?php if ($boardMeta !== []): ?>
                                                <span class="muted minutes-attendee-meta"><?= e(implode(' · ', $boardMeta)) ?></span>
                                            <?php endif; ?>
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
                                    $memberNumber = trim((string) ($member['number'] ?? ''));
                                    $presence = (string) ($memberPresence[$memberId] ?? 'absent');
                                    ?>
                                    <tr data-minutes-attendee data-minutes-member-row data-search="<?= e(trim(mb_strtolower((string) $member['label']) . ' ' . $memberNumber)) ?>">
                                        <td>
                                            <strong><?= e((string) $member['label']) ?></strong>
                                            <?php if ($memberNumber !== ''): ?>
                                                <span class="muted"><?= e(__('documents.minutes_member_number', ['number' => $memberNumber])) ?></span>
                                            <?php endif; ?>
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
                    <?php if (($budget_text ?? '') !== '' || ($budget_voices['years'] ?? []) !== []): ?>
                        <button type="button" class="btn btn-ghost btn-sm" data-minutes-insert="budget" data-minutes-budget-text="<?= e((string) ($budget_text ?? '')) ?>"><?= e(__('documents.minutes_insert_budget')) ?></button>
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
                    'value' => (string) old('minutes_time_close', date('H:i')),
                    'input_type' => 'time',
                    'required' => true,
                    'attrs' => ['data-minutes-time-default' => date('H:i')],
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

<dialog
        class="setup-exit-dialog"
        data-minutes-budget-dialog
        data-tpl-planned="<?= e((string) __('documents.minutes_budget_line_planned')) ?>"
        data-tpl-actual="<?= e((string) __('documents.minutes_budget_line_actual')) ?>"
        data-tpl-delta="<?= e((string) __('documents.minutes_budget_line_delta')) ?>"
        data-label-totals="<?= e((string) __('documents.minutes_budget_totals')) ?>"
        data-label-total-income="<?= e((string) __('documents.minutes_budget_total_income')) ?>"
        data-label-total-expense="<?= e((string) __('documents.minutes_budget_total_expense')) ?>"
        data-msg-nothing="<?= e((string) __('documents.minutes_budget_nothing')) ?>"
    >
        <div class="setup-exit-shell">
            <p class="setup-exit-text"><?= e(__('documents.minutes_budget_dialog_title')) ?></p>
            <div class="setup-equal-row">
                <?= field('select', [
                    'name' => '',
                    'id' => 'minutes-budget-year',
                    'label' => (string) __('documents.minutes_budget_year'),
                    'value' => '',
                    'include_empty' => false,
                    'options' => [],
                    'attrs' => ['data-minutes-budget-year' => true],
                    'context' => 'member',
                ]) ?>
                <?= field('select', [
                    'name' => '',
                    'id' => 'minutes-budget-datum',
                    'label' => (string) __('documents.minutes_budget_datum'),
                    'value' => 'planned',
                    'include_empty' => false,
                    'options' => [
                        ['value' => 'planned', 'label' => (string) __('documents.minutes_budget_datum_planned')],
                        ['value' => 'actual', 'label' => (string) __('documents.minutes_budget_datum_actual')],
                        ['value' => 'delta', 'label' => (string) __('documents.minutes_budget_datum_delta')],
                    ],
                    'attrs' => ['data-minutes-budget-datum' => true],
                    'context' => 'member',
                ]) ?>
            </div>
            <?= field('select', [
                'name' => '',
                'id' => 'minutes-budget-voice',
                'label' => (string) __('documents.minutes_budget_voice'),
                'value' => '',
                'include_empty' => false,
                'options' => [],
                'attrs' => ['data-minutes-budget-voice' => true],
                'context' => 'member',
            ]) ?>
            <p class="setup-hint muted" data-minutes-budget-preview></p>
            <div class="setup-exit-actions">
                <button type="button" class="btn btn-ghost" data-minutes-budget-cancel><?= e(__('documents.minutes_budget_cancel')) ?></button>
                <button type="button" class="btn" data-minutes-budget-insert><?= e(__('documents.minutes_budget_confirm')) ?></button>
            </div>
        </div>
    </dialog>
