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
 * One agenda point card. Shared by the first card and the JS <template> clone.
 *
 * @var int $index
 * @var string $title
 * @var string $text
 * @var string $outcome
 * @var int $yes
 * @var int $no
 * @var int $abstain
 * @var bool $required
 */
$index = (int) ($index ?? 1);
$title = (string) ($title ?? '');
$text = (string) ($text ?? '');
$outcome = (string) ($outcome ?? 'majority');
$yes = (int) ($yes ?? 0);
$no = (int) ($no ?? 0);
$abstain = (int) ($abstain ?? 0);
$required = !empty($required);
?>
<article class="minutes-agenda-card" data-minutes-agenda-item>
    <header class="minutes-agenda-head">
        <span class="minutes-agenda-index" data-minutes-agenda-index><?= (int) $index ?></span>
        <button type="button" class="btn btn-ghost btn-sm" data-minutes-agenda-remove><?= e(__('documents.minutes_agenda_remove')) ?></button>
    </header>
    <?= field('text', [
        'name' => 'agenda_title[]',
        'label' => (string) __('documents.minutes_agenda_title'),
        'value' => $title,
        'required' => $required,
        'maxlength' => 190,
        'context' => 'member',
    ]) ?>
    <?= field('textarea', [
        'name' => 'agenda_text[]',
        'label' => (string) __('documents.minutes_agenda_text'),
        'value' => $text,
        'rows' => 3,
        'context' => 'member',
    ]) ?>
    <div class="setup-equal-row">
        <?= field('select', [
            'name' => 'agenda_outcome[]',
            'label' => (string) __('documents.minutes_agenda_outcome'),
            'value' => $outcome,
            'include_empty' => false,
            'options' => [
                ['value' => 'unanimous', 'label' => (string) __('documents.minutes_outcome_unanimous')],
                ['value' => 'majority', 'label' => (string) __('documents.minutes_outcome_majority')],
                ['value' => 'rejected', 'label' => (string) __('documents.minutes_outcome_rejected')],
                ['value' => 'not_voted', 'label' => (string) __('documents.minutes_outcome_not_voted')],
            ],
            'context' => 'member',
        ]) ?>
        <div class="minutes-vote-row">
            <?= field('number', ['name' => 'agenda_yes[]', 'label' => (string) __('documents.minutes_vote_yes'), 'value' => (string) $yes, 'min' => 0, 'context' => 'member']) ?>
            <?= field('number', ['name' => 'agenda_no[]', 'label' => (string) __('documents.minutes_vote_no'), 'value' => (string) $no, 'min' => 0, 'context' => 'member']) ?>
            <?= field('number', ['name' => 'agenda_abstain[]', 'label' => (string) __('documents.minutes_vote_abstain'), 'value' => (string) $abstain, 'min' => 0, 'context' => 'member']) ?>
        </div>
    </div>
</article>
