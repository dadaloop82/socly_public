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

/** @var array<string,mixed> $document */
/** @var string $category_label */
/** @var array<string,mixed>|null $minutes */
/** @var list<array<string,mixed>> $repository_links */
$docId = (int) ($document['id'] ?? 0);
$docTitle = (string) ($document['title'] ?? '');
$language = trim((string) ($document['language'] ?? ''));
$languageLabel = $language !== '' ? __('documents.language_' . $language) : '—';
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e((string) ($document['title'] ?? __('documents.title'))) ?></h1>
        <p class="page-lede"><?= e(__('documents.detail_lede')) ?></p>
        <?php if ($docId > 0): ?>
            <?= view_partial('partials/record_trail', ['entity' => 'document', 'id' => $docId]) ?>
        <?php endif; ?>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/documents')) ?>"><?= e(__('common.back')) ?></a>
        <?php
        $lockedMinutes = (string) ($document['source_ref'] ?? '') === 'minutes'
            && in_array((string) ($document['status'] ?? ''), ['approved', 'signed', 'archived'], true);
        ?>
        <?php if (can('documents.manage') && !$lockedMinutes): ?>
            <a class="btn" href="<?= e(url('/documents/' . $docId . '/edit')) ?>"><?= e(__('documents.edit')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="grid-3">
        <div><span class="muted"><?= e(__('documents.number')) ?></span><br><strong><?= e((string) ($document['document_number'] ?? '') ?: '—') ?></strong></div>
        <div><span class="muted"><?= e(__('documents.date')) ?></span><br><strong><?= e(format_date($document['document_date'] ?? null) ?: '—') ?></strong></div>
        <div><span class="muted"><?= e(__('documents.category')) ?></span><br><span class="doc-category-badge"><?= e($category_label) ?></span></div>
        <div><span class="muted"><?= e(__('documents.language')) ?></span><br><strong><?= e($languageLabel) ?></strong></div>
        <div><span class="muted"><?= e(__('documents.status')) ?></span><br><span class="doc-status doc-status-<?= e((string) ($document['status'] ?? 'draft')) ?>"><?= e(__('documents.status_' . (string) ($document['status'] ?? 'draft'))) ?></span></div>
        <div><span class="muted"><?= e(__('documents.filter_section')) ?></span><br><a href="<?= e(url('/documents?section=' . rawurlencode((string) ($document['source_section'] ?? 'documents')))) ?>"><?= e((string) ($section_label ?? '—')) ?></a></div>
        <div><span class="muted"><?= e(__('documents.uploaded_by')) ?></span><br><strong><?= e((string) (($document['uploaded_by_name'] ?? '') ?: ($document['author_name'] ?? '—'))) ?></strong><br><span class="muted"><?= e(format_datetime($document['created_at'] ?? null) ?: '—') ?></span></div>
        <div><span class="muted"><?= e(__('documents.reference_period')) ?></span><br><strong><?php
            $periodLabel = document_period_label(is_array($document) ? $document : []);
            echo e($periodLabel !== '' ? $periodLabel : '—');
        ?></strong></div>
        <div><span class="muted"><?= e(__('documents.file_size')) ?></span><br><strong><?= (int) ($document['file_size'] ?? 0) > 0 ? e(number_format((int) $document['file_size'] / 1024, 0, ',', '.') . ' KB') : '—' ?></strong></div>
        <div><span class="muted"><?= e(__('documents.downloads')) ?></span><br><strong><?= (int) ($document['download_count'] ?? 0) ?></strong></div>
    </div>
    <?php if (!empty($document['file_path'])): ?>
        <div class="doc-file-actions">
            <a class="doc-file-link" href="<?= e(url('/documents/' . $docId . '/file')) ?>" target="_blank" rel="noopener">
                <?= row_action_icon_svg('view') ?><span><?= e(__('documents.open_file')) ?></span>
            </a>
            <a class="doc-file-link" href="<?= e(url('/documents/' . $docId . '/download')) ?>">
                <?= row_action_icon_svg('download') ?><span><?= e(__('documents.download_file')) ?></span>
            </a>
            <?php if (trim((string) ($signed_url ?? '')) !== ''): ?>
                <a class="doc-file-link" href="<?= e((string) $signed_url) ?>" target="_blank" rel="noopener">
                    <?= row_action_icon_svg('external') ?><span><?= e(__('documents.signed_link')) ?></span>
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="muted doc-file-actions"><?= e(__('documents.no_file')) ?></p>
    <?php endif; ?>
</div>

<?php if (is_array($minutes ?? null) && $minutes !== []): ?>
    <?php
    $mType = (string) ($minutes['type_label'] ?? '');
    $mDate = (string) ($minutes['date'] ?? '');
    $mOpen = (string) ($minutes['time_open'] ?? '');
    $mClose = (string) ($minutes['time_close'] ?? '');
    $mMode = (string) ($minutes['mode'] ?? 'free');
    $mPresident = (string) ($minutes['president'] ?? '');
    $mSecretary = (string) ($minutes['secretary'] ?? '');
    $mPlaceMode = (string) ($minutes['place_mode'] ?? 'in_person');
    $mPlace = (string) ($minutes['place'] ?? '');
    $mPlatform = (string) ($minutes['platform'] ?? '');
    $mVideoLink = (string) ($minutes['video_link'] ?? '');
    $mConvoked = !empty($minutes['convoked']);
    $mConvDate = (string) ($minutes['convocation_date'] ?? '');
    $mBoard = is_array($minutes['board'] ?? null) ? $minutes['board'] : [];
    $mAssembly = is_array($minutes['assembly'] ?? null) ? $minutes['assembly'] : [];
    $mAgenda = is_array($minutes['agenda'] ?? null) ? $minutes['agenda'] : [];
    $mQuorum = is_array($minutes['quorum'] ?? null) ? $minutes['quorum'] : [];
    $mTime = trim($mOpen . '–' . $mClose, '–');
    $mVenue = $mPlaceMode === 'video'
        ? trim(($mPlatform !== '' ? $mPlatform : '') . ($mVideoLink !== '' ? ' — ' . $mVideoLink : ''), ' —')
        : $mPlace;
    ?>
    <section class="panel doc-minutes">
        <div class="doc-minutes-head">
            <div>
                <p class="doc-minutes-kicker"><?= e(__('documents.minutes_panel_kicker')) ?></p>
                <h2 class="section-title"><?= e($mType !== '' ? $mType : (string) __('documents.minutes_panel_title')) ?></h2>
                <p class="muted">
                    <?php if ($mDate !== ''): ?><?= e(format_date($mDate) ?: $mDate) ?><?php endif; ?>
                    <?php if ($mTime !== ''): ?> · <?= e($mTime) ?><?php endif; ?>
                </p>
            </div>
            <?php if (trim((string) ($document['document_number'] ?? '')) !== ''): ?>
                <span class="doc-minutes-number"><?= e(__('documents.minutes_number_badge', ['number' => (string) $document['document_number']])) ?></span>
            <?php endif; ?>
        </div>

        <div class="doc-minutes-officers">
            <div>
                <span class="muted"><?= e(__('documents.minutes_field_president')) ?></span>
                <strong><?= e($mPresident !== '' ? $mPresident : '—') ?></strong>
            </div>
            <div>
                <span class="muted"><?= e(__('documents.minutes_field_secretary')) ?></span>
                <strong><?= e($mSecretary !== '' ? $mSecretary : '—') ?></strong>
            </div>
            <div>
                <span class="muted"><?= e(__('documents.minutes_field_convocation')) ?></span>
                <strong>
                    <?php if ($mConvoked): ?>
                        <?= e($mConvDate !== '' ? __('documents.minutes_convoked_on', ['date' => format_date($mConvDate) ?: $mConvDate]) : __('documents.minutes_convoked_regularly')) ?>
                    <?php else: ?>
                        <?= e(__('documents.minutes_convoked_no')) ?>
                    <?php endif; ?>
                </strong>
            </div>
            <div>
                <span class="muted"><?= e($mPlaceMode === 'video' ? __('documents.minutes_place_video') : __('documents.minutes_field_place')) ?></span>
                <strong><?= e($mVenue !== '' ? $mVenue : '—') ?></strong>
            </div>
        </div>

        <?php if (in_array($mMode, ['board', 'assembly'], true) && $mQuorum !== []): ?>
            <p class="doc-minutes-quorum">
                <span class="doc-minutes-chip"><strong><?= (int) ($mQuorum['present'] ?? 0) ?></strong> <?= e(__('documents.minutes_quorum_present_chip')) ?></span>
                <?php if ((int) ($mQuorum['delegations'] ?? 0) > 0): ?>
                    <span class="doc-minutes-chip"><strong><?= (int) $mQuorum['delegations'] ?></strong> <?= e(__('documents.minutes_quorum_delegations_chip')) ?></span>
                <?php endif; ?>
                <span class="doc-minutes-chip"><strong><?= (int) ($mQuorum['total'] ?? 0) ?></strong> <?= e(__('documents.minutes_quorum_total_chip')) ?></span>
                <span class="doc-minutes-quorum-badge <?= !empty($mQuorum['reached']) ? 'is-ok' : 'is-missing' ?>">
                    <?= e(!empty($mQuorum['reached']) ? __('documents.minutes_quorum_reached') : __('documents.minutes_quorum_missing')) ?>
                    · <?= e(__('documents.minutes_quorum_required', ['count' => (string) (int) ($mQuorum['required'] ?? 0)])) ?>
                </span>
            </p>
        <?php endif; ?>

        <?php if ($mBoard !== []): ?>
            <h3 class="doc-minutes-subtitle"><?= e(__('documents.minutes_board_attendance')) ?></h3>
            <ul class="doc-minutes-people">
                <?php foreach ($mBoard as $row): ?>
                    <?php $state = (string) ($row['attendance'] ?? 'absent'); ?>
                    <li>
                        <span class="doc-minutes-person">
                            <strong><?= e((string) ($row['name'] ?? '')) ?></strong>
                            <?php if ((string) ($row['role'] ?? '') !== ''): ?><span class="muted"><?= e((string) $row['role']) ?></span><?php endif; ?>
                        </span>
                        <span class="doc-minutes-attendance is-<?= e($state) ?>"><?= e(__('documents.minutes_attendance_' . $state)) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($mAssembly !== []): ?>
            <h3 class="doc-minutes-subtitle"><?= e(__('documents.minutes_assembly_attendance')) ?></h3>
            <ul class="doc-minutes-people">
                <?php foreach ($mAssembly as $row): ?>
                    <?php
                    $state = (string) ($row['presence'] ?? 'absent');
                    $delegate = trim((string) ($row['delegate'] ?? ''));
                    $stateLabel = $state === 'delegate'
                        ? ($delegate !== ''
                            ? (string) __('documents.minutes_presence_delegate', ['name' => $delegate])
                            : (string) __('documents.minutes_presence_delegate_generic'))
                        : (string) __('documents.minutes_presence_' . $state);
                    ?>
                    <li>
                        <span class="doc-minutes-person"><strong><?= e((string) ($row['name'] ?? '')) ?></strong></span>
                        <span class="doc-minutes-attendance is-<?= e($state) ?>"><?= e($stateLabel) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($mAgenda !== []): ?>
            <h3 class="doc-minutes-subtitle"><?= e(__('documents.minutes_agenda_outcomes_title')) ?></h3>
            <ol class="doc-minutes-agenda">
                <?php foreach ($mAgenda as $index => $item): ?>
                    <?php $outcome = (string) ($item['outcome'] ?? 'majority'); ?>
                    <li class="doc-minutes-agenda-item">
                        <div class="doc-minutes-agenda-head">
                            <span class="doc-minutes-agenda-index"><?= (int) $index + 1 ?></span>
                            <strong class="doc-minutes-agenda-title"><?= e((string) ($item['title'] ?? '')) ?></strong>
                        </div>
                        <?php if ((string) ($item['text'] ?? '') !== ''): ?>
                            <p class="doc-minutes-agenda-text"><?= nl2br(e((string) $item['text'])) ?></p>
                        <?php endif; ?>
                        <div class="doc-minutes-agenda-foot">
                            <span class="doc-minutes-outcome is-<?= e($outcome) ?>"><?= e(__('documents.minutes_outcome_' . $outcome)) ?></span>
                            <?php if ($mMode !== 'free'): ?>
                                <span class="doc-minutes-votes">
                                    <span><strong><?= (int) ($item['yes'] ?? 0) ?></strong> <?= e(__('documents.minutes_vote_yes')) ?></span>
                                    <span><strong><?= (int) ($item['no'] ?? 0) ?></strong> <?= e(__('documents.minutes_vote_no')) ?></span>
                                    <span><strong><?= (int) ($item['abstain'] ?? 0) ?></strong> <?= e(__('documents.minutes_vote_abstain')) ?></span>
                                </span>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php
$hashValue = trim((string) ($document['content_hash'] ?? ''));
$showSummary = !is_array($minutes ?? null) && trim((string) ($document['summary'] ?? '')) !== '';
?>
<?php if ($hashValue !== '' || !empty($repository_links) || $showSummary): ?>
    <section class="panel doc-integrity">
        <?php if ($hashValue !== ''): ?>
            <div class="doc-hash">
                <div class="doc-hash-copy">
                    <span class="doc-hash-label"><?= e(__('documents.content_hash')) ?></span>
                    <p class="doc-hash-hint"><?= e(__('documents.content_hash_hint')) ?></p>
                </div>
                <div class="doc-hash-value">
                    <code><?= e($hashValue) ?></code>
                    <button
                        type="button"
                        class="btn btn-sm btn-ghost"
                        data-copy-text="<?= e($hashValue) ?>"
                        data-copied="<?= e(__('documents.hash_copied')) ?>"
                    ><?= e(__('documents.hash_copy')) ?></button>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($repository_links)): ?>
            <div class="doc-links">
                <span class="doc-hash-label"><?= e(__('documents.repository_links_title')) ?></span>
                <ul class="documents-repository-links">
                    <?php foreach ($repository_links as $repoLink): ?>
                        <li>
                            <span class="documents-section-badge"><?= e((string) ($repoLink['section_label'] ?? $repoLink['section'] ?? '')) ?></span>
                            <?php if (trim((string) ($repoLink['context_label'] ?? '')) !== ''): ?>
                                <span><?= e((string) $repoLink['context_label']) ?></span>
                            <?php endif; ?>
                            <span class="muted"><?= e(format_datetime($repoLink['created_at'] ?? null) ?: '') ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($showSummary): ?>
            <div class="doc-summary">
                <span class="doc-hash-label"><?= e(__('documents.summary')) ?></span>
                <p><?= nl2br(e(document_summary_display((string) $document['summary']))) ?></p>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (can('documents.manage') && (string) ($document['status'] ?? '') === 'draft'): ?>
    <section class="panel treasury-danger-zone documents-danger-zone">
        <div>
            <h2 class="section-title"><?= e(__('documents.delete_draft_title')) ?></h2>
            <p class="muted"><?= e(__('documents.delete_draft_hint')) ?></p>
        </div>
        <form method="post" action="<?= e(url('/documents/' . $docId . '/delete')) ?>"
              data-confirm="<?= e(__('documents.delete_draft_confirm', ['title' => $docTitle])) ?>"
              data-confirm-second="<?= e(__('documents.delete_draft_confirm_again', ['title' => $docTitle])) ?>"
              data-confirm-danger="1">
            <?= csrf_field() ?>
            <button class="btn btn-danger" type="submit"><?= e(__('documents.delete_draft_button')) ?></button>
        </form>
    </section>
<?php endif; ?>
