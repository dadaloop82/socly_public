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
 * Minutes and documents — list page in the style of Members, First Cash and Schedule: header with
 * primary action, clickable KPIs, live filter bar and archive grouped by category with clickable
 * rows.
 *
 * @var list<array> $documents
 * @var array{total:int,draft:int,approved:int,expiring:int} $counts
 * @var array{status:string,category:string,expiring:bool} $filters
 * @var list<array{key:string,label:string,builtin:bool}> $categories
 * @var list<string> $languages
 * @var string $search_query
 */
$canManage = can('documents.manage');
$query = trim((string) ($search_query ?? ''));
$filterStatus = (string) ($filters['status'] ?? '');
$filterCategory = (string) ($filters['category'] ?? '');
$filterExpiring = !empty($filters['expiring']);
$filterSection = (string) ($filters['section'] ?? '');
$filterYear = (int) ($filters['year'] ?? 0);
$filterUploader = (string) ($filters['uploader'] ?? '');
// “Expiring” is an item in the same Status menu (no steering wheel control).
$statusParam = $filterExpiring ? 'expiring' : $filterStatus;
$hasActiveFilters = $query !== '' || $statusParam !== '' || $filterCategory !== ''
    || $filterSection !== '' || $filterYear > 0 || $filterUploader !== '';

$categoryLabels = [];
foreach ($categories as $category) {
    $categoryLabels[(string) $category['key']] = (string) $category['label'];
}
$sectionLabels = is_array($repository_sections ?? null) ? $repository_sections : [];
$today = date('Y-m-d');
$soon = date('Y-m-d', strtotime('+30 days'));

// Keeps the other filters when one of them changes.
$filterUrl = static function (array $overrides = []) use ($query, $statusParam, $filterCategory, $filterSection, $filterYear, $filterUploader): string {
    $params = [];
    if ($query !== '') {
        $params['q'] = $query;
    }
    if ($statusParam !== '') {
        $params['status'] = $statusParam;
    }
    if ($filterCategory !== '') {
        $params['category'] = $filterCategory;
    }
    if ($filterSection !== '') {
        $params['section'] = $filterSection;
    }
    if ($filterYear > 0) {
        $params['year'] = (string) $filterYear;
    }
    if ($filterUploader !== '') {
        $params['uploader'] = $filterUploader;
    }
    foreach ($overrides as $key => $value) {
        if ($value === '' || $value === null || $value === false) {
            unset($params[$key]);
            continue;
        }
        $params[$key] = (string) $value;
    }
    return url('/documents') . ($params !== [] ? '?' . http_build_query($params) : '');
};

$statusFilterOptions = [
    'draft' => (string) __('documents.status_draft'),
    'pending_approval' => (string) __('documents.status_pending_approval'),
    'approved' => (string) __('documents.status_approved'),
    'signed' => (string) __('documents.status_signed'),
    'archived' => (string) __('documents.status_archived'),
    'cancelled' => (string) __('documents.status_cancelled'),
    'expiring' => (string) __('documents.stats_expiring'),
];

// Archive grouped by category, in the order of the configured categories.
$groups = [];
$groupOrder = [];
foreach ($categories as $category) {
    $key = (string) $category['key'];
    $groupOrder[] = $key;
    $groups[$key] = [];
}
foreach ($documents as $doc) {
    $key = trim((string) ($doc['category'] ?? ''));
    if ($key === '') {
        $key = 'other';
    }
    if (!isset($groups[$key])) {
        $groups[$key] = [];
        $groupOrder[] = $key;
    }
    $groups[$key][] = $doc;
}
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('documents.title')) ?></h1>
        <p class="page-lede"><?= e(__('documents.lede')) ?></p>
    </div>
    <div class="actions">
        <?php if ($hasActiveFilters): ?>
            <a class="btn btn-ghost" href="<?= e($filterUrl(['q' => '', 'status' => '', 'category' => '', 'section' => '', 'year' => '', 'uploader' => ''])) ?>"><?= e(__('documents.filter_reset')) ?></a>
        <?php endif; ?>
        <?php
        $excerptRepair = is_array($excerpt_repair ?? null) ? $excerpt_repair : [];
        $excerptMissing = (int) ($excerptRepair['missing'] ?? 0);
        $excerptAddon = !empty($excerptRepair['available']);
        ?>
        <?php if ($canManage): ?>
            <button
                type="button"
                class="btn btn-ghost"
                data-repair-excerpts
                data-repair-start="<?= e(url('/documents/repair-excerpts')) ?>"
                data-repair-tick="<?= e(url('/documents/repair-excerpts/tick')) ?>"
                data-repair-missing="<?= (int) $excerptMissing ?>"
                data-repair-addon="<?= $excerptAddon ? '1' : '0' ?>"
                data-repair-title="<?= e(__('documents.repair_excerpts_title')) ?>"
                data-repair-starting="<?= e(__('documents.repair_excerpts_starting')) ?>"
                data-repair-working="<?= e(__('documents.repair_excerpts_working')) ?>"
                data-repair-eta="<?= e(__('documents.repair_excerpts_eta')) ?>"
                data-repair-done="<?= e(__('documents.repair_excerpts_done')) ?>"
                data-repair-failed="<?= e(__('documents.repair_excerpts_failed')) ?>"
                data-repair-synced="<?= e(__('documents.repair_excerpts_synced')) ?>"
                data-repair-unavailable="<?= e(__('documents.repair_excerpts_unavailable')) ?>"
                data-repair-retry="<?= e(__('documents.repair_excerpts_retry')) ?>"
                title="<?= e($excerptMissing > 0
                    ? __('documents.repair_excerpts_hint', ['count' => (string) $excerptMissing])
                    : __('documents.repair_excerpts_synced')) ?>"
            ><?= e(__('documents.repair_excerpts')) ?></button>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <a class="btn<?= ($documents === [] && !$hasActiveFilters) ? ' is-first-cta' : '' ?>" href="<?= e(url('/documents/create')) ?>"><?= e(__('documents.add')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="stats stats-context-documents">
    <a class="stat" href="<?= e($filterUrl(['q' => '', 'status' => '', 'category' => '', 'expiring' => false])) ?>">
        <div class="label"><?= e(__('documents.stats_total')) ?></div>
        <div class="value"><?= (int) ($counts['total'] ?? 0) ?></div>
    </a>
    <a class="stat<?= $statusParam === 'draft' ? ' is-active' : '' ?>" href="<?= e($filterUrl(['status' => $statusParam === 'draft' ? '' : 'draft'])) ?>">
        <div class="label"><?= e(__('documents.status_draft')) ?></div>
        <div class="value"><?= (int) ($counts['draft'] ?? 0) ?></div>
    </a>
    <a class="stat<?= $statusParam === 'approved' ? ' is-active' : '' ?>" href="<?= e($filterUrl(['status' => $statusParam === 'approved' ? '' : 'approved'])) ?>">
        <div class="label"><?= e(__('documents.status_approved')) ?></div>
        <div class="value"><?= (int) ($counts['approved'] ?? 0) ?></div>
    </a>
    <a class="stat<?= $filterExpiring ? ' is-active' : '' ?>" href="<?= e($filterUrl(['status' => $filterExpiring ? '' : 'expiring'])) ?>">
        <div class="label"><?= e(__('documents.stats_expiring')) ?></div>
        <div class="value<?= (int) ($counts['expiring'] ?? 0) > 0 ? ' stat-negative' : '' ?>"><?= (int) ($counts['expiring'] ?? 0) ?></div>
    </a>
</div>

<?php
$repoStats = is_array($repository_stats ?? null) ? $repository_stats : [];
$repoSize = (int) ($repoStats['size'] ?? 0);
$repoSizeLabel = $repoSize >= 1048576
    ? number_format($repoSize / 1048576, 1, ',', '.') . ' MB'
    : (string) max(0, (int) round($repoSize / 1024)) . ' KB';
?>
<section class="panel documents-repository">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('documents.repository_title')) ?></h2>
            <p class="section-lede"><?= e(__('documents.repository_lede')) ?></p>
        </div>
        <?php if ($canManage): ?>
            <form method="post" action="<?= e(url('/documents/reindex')) ?>" data-confirm="<?= e(__('documents.reindex_confirm')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-ghost btn-sm" type="submit"><?= e(__('documents.reindex')) ?></button>
            </form>
        <?php endif; ?>
    </div>
    <div class="documents-repository-stats">
        <span class="documents-repository-stat"><strong><?= (int) ($repoStats['files'] ?? 0) ?></strong> <?= e(__('documents.repository_files')) ?></span>
        <span class="documents-repository-stat"><strong><?= e($repoSizeLabel) ?></strong> <?= e(__('documents.repository_size')) ?></span>
        <span class="documents-repository-stat"><strong><?= (int) ($repoStats['linked'] ?? 0) ?></strong> <?= e(__('documents.repository_links')) ?></span>
        <span class="documents-repository-stat"><strong><?= (int) ($repoStats['current_year'] ?? 0) ?></strong> <?= e(__('documents.repository_current_year')) ?></span>
        <?php if ((int) ($repoStats['duplicates'] ?? 0) > 0): ?>
            <span class="documents-repository-stat is-warning"><strong><?= (int) $repoStats['duplicates'] ?></strong> <?= e(__('documents.repository_duplicates')) ?></span>
        <?php endif; ?>
    </div>
</section>

<?php if ($documents !== [] || $hasActiveFilters): ?>
<form class="panel filter-bar members-filter documents-filter" method="get" action="<?= e(url('/documents')) ?>" role="search" data-documents-filter>
    <div class="members-filter-row documents-filter-row">
        <div class="members-filter-q">
            <?= field('search', [
                'name' => 'q',
                'id' => 'documents-q',
                'label' => (string) __('documents.search'),
                'value' => $query,
                'context' => 'filter',
                'placeholder' => (string) __('documents.search_placeholder'),
                'maxlength' => 120,
                'attrs' => ['enterkeyhint' => 'search', 'autocomplete' => 'off'],
            ]) ?>
        </div>
        <?= field('select', [
            'name' => 'category',
            'id' => 'documents-category-filter',
            'label' => (string) __('documents.filter_category'),
            'value' => $filterCategory,
            'context' => 'filter',
            'empty_label' => (string) __('documents.filter_all'),
            'options' => $categoryLabels,
        ]) ?>
        <?= field('select', [
            'name' => 'status',
            'id' => 'documents-status-filter',
            'label' => (string) __('documents.filter_status'),
            'value' => $statusParam,
            'context' => 'filter',
            'empty_label' => (string) __('documents.filter_all'),
            'options' => $statusFilterOptions,
        ]) ?>
        <?= field('select', [
            'name' => 'section',
            'id' => 'documents-section-filter',
            'label' => (string) __('documents.filter_section'),
            'value' => $filterSection,
            'context' => 'filter',
            'empty_label' => (string) __('documents.filter_all'),
            'options' => (array) ($repository_sections ?? []),
        ]) ?>
        <?php
        $yearOptions = [];
        foreach ((array) ($repository_years ?? []) as $repositoryYear) {
            $yearOptions[(string) (int) $repositoryYear] = (string) (int) $repositoryYear;
        }
        ?>
        <?= field('select', [
            'name' => 'year',
            'id' => 'documents-year-filter',
            'label' => (string) __('documents.filter_year'),
            'value' => $filterYear > 0 ? (string) $filterYear : '',
            'context' => 'filter',
            'empty_label' => (string) __('documents.filter_all'),
            'options' => $yearOptions,
        ]) ?>
        <?= field('text', [
            'name' => 'uploader',
            'id' => 'documents-uploader-filter',
            'label' => (string) __('documents.filter_uploader'),
            'value' => $filterUploader,
            'context' => 'filter',
            'placeholder' => (string) __('documents.filter_uploader_placeholder'),
            'maxlength' => 120,
            'attrs' => ['autocomplete' => 'off'],
        ]) ?>
        <?php if ($hasActiveFilters): ?>
            <a class="btn btn-ghost btn-sm documents-filter-reset" href="<?= e($filterUrl(['q' => '', 'status' => '', 'category' => '', 'section' => '', 'year' => '', 'uploader' => ''])) ?>"><?= e(__('documents.filter_reset')) ?></a>
        <?php endif; ?>
    </div>
</form>
<?php endif; ?>


<section class="panel documents-minutes">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('documents.minutes_title')) ?></h2>
            <p class="section-lede"><?= e(__('documents.minutes_lede', ['year' => (string) date('Y')])) ?></p>
        </div>
    </div>
    <div class="documents-minutes-grid">
        <?php foreach (($minutes_overview ?? []) as $book): ?>
            <?php
            $bookKey = (string) ($book['key'] ?? '');
            $bookYear = (int) ($book['current_year'] ?? 0);
            $bookTotal = (int) ($book['total'] ?? 0);
            $bookYearNumber = (int) ($book['year'] ?? date('Y'));
            $bookLast = trim((string) ($book['last_date'] ?? ''));
            ?>
            <article class="documents-minutes-card<?= $bookYear === 0 ? ' is-missing' : '' ?>">
                <span class="documents-minutes-label"><?= e((string) ($book['label'] ?? $bookKey)) ?></span>
                <strong class="documents-minutes-count"><?= $bookYear ?></strong>
                <span class="documents-minutes-meta"><?= e(__('documents.minutes_total', ['count' => (string) $bookTotal])) ?></span>
                <?php if ($bookYear === 0): ?>
                    <span class="documents-minutes-warning"><?= e(__('documents.minutes_missing', ['year' => (string) $bookYearNumber])) ?></span>
                <?php elseif ($bookLast !== ''): ?>
                    <span class="documents-minutes-meta muted"><?= e(__('documents.minutes_last', ['date' => format_date($bookLast) ?: $bookLast])) ?></span>
                <?php endif; ?>
                <?php if ($canManage): ?>
                    <a class="btn btn-sm<?= $bookYear === 0 ? '' : ' btn-ghost' ?>" href="<?= e(url('/documents/create?category=' . rawurlencode($bookKey))) ?>"><?= e(__('documents.minutes_add')) ?></a>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('documents.archive')) ?></h2>
            <p class="section-lede"><?= e(__('documents.archive_lede')) ?></p>
        </div>
    </div>
    <?php if ($documents === []): ?>
        <div class="empty-state">
            <?php if ($hasActiveFilters): ?>
                <strong><?= e(__('documents.search_empty_title')) ?></strong>
                <?= e(__('documents.search_empty_text', ['q' => $query !== '' ? $query : ($filterCategory !== '' ? $filterCategory : $filterStatus)])) ?>
            <?php else: ?>
                <strong><?= e(__('documents.empty_title')) ?></strong>
                <?= e(__('documents.empty_text')) ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="doc-archive documents-archive">
            <?php foreach ($groupOrder as $groupKey): ?>
                <?php $groupItems = $groups[$groupKey] ?? []; ?>
                <?php if ($groupItems === []) { continue; } ?>
                <?php
                // Dense secondary archives (misc files, statement crops) stay collapsed by default.
                $isCollapsibleGroup = in_array($groupKey, ['other', 'statement_excerpt'], true);
                $groupOpen = $isCollapsibleGroup && $filterCategory === $groupKey;
                $groupTag = $isCollapsibleGroup ? 'details' : 'section';
                ?>
                <<?= $groupTag ?> class="doc-archive-group<?= $isCollapsibleGroup ? ' doc-archive-group--collapsible' : '' ?>"<?= $groupOpen ? ' open' : '' ?>>
                    <?php if ($isCollapsibleGroup): ?>
                        <summary class="doc-archive-group-head">
                            <h3 class="doc-archive-group-title"><?= e($categoryLabels[$groupKey] ?? $groupKey) ?></h3>
                            <span class="doc-archive-group-count"><?= count($groupItems) ?></span>
                        </summary>
                    <?php else: ?>
                        <header class="doc-archive-group-head">
                            <h3 class="doc-archive-group-title"><?= e($categoryLabels[$groupKey] ?? $groupKey) ?></h3>
                            <span class="doc-archive-group-count"><?= count($groupItems) ?></span>
                        </header>
                    <?php endif; ?>
                    <div class="table-wrap embedded">
                        <table class="documents-table">
                            <thead>
                            <tr>
                                <th><?= e(__('documents.date')) ?></th>
                                <th><?= e(__('documents.title_field')) ?></th>
                                <th><?= e(__('documents.number')) ?></th>
                                <th><?= e(__('documents.filter_section')) ?></th>
                                <th><?= e(__('documents.uploaded_by')) ?></th>
                                <th><?= e(__('documents.status')) ?></th>
                                <th><?= e(__('documents.actions')) ?></th>
                            </tr>
                            </thead>
                            <tbody>

                            <?php foreach ($groupItems as $doc): ?>
                                <?php
                                $docId = (int) ($doc['id'] ?? 0);
                                $detailUrl = url('/documents/' . $docId);
                                $docStatus = (string) ($doc['status'] ?? 'draft');
                                $docDate = (string) ($doc['document_date'] ?? '');
                                $docNumber = trim((string) ($doc['document_number'] ?? ''));
                                $summary = trim((string) ($doc['summary'] ?? ''));
                                $hasFile = trim((string) ($doc['file_path'] ?? '')) !== '';
                                $lang = trim((string) ($doc['language'] ?? ''));
                                $langLabel = '';
                                if ($lang !== '') {
                                    $langLabel = (string) __('documents.language_' . $lang);
                                    if ($langLabel === 'documents.language_' . $lang) {
                                        $langLabel = $lang;
                                    }
                                }
                                $expires = trim((string) ($doc['expires_at'] ?? ''));
                                $expiresPast = $expires !== '' && $expires < $today;
                                $expiresSoon = $expires !== '' && !$expiresPast && $expires <= $soon;
                                ?>
                                <tr
                                    class="documents-row doc-row-editable"
                                    data-href="<?= e($detailUrl) ?>"
                                    tabindex="0"
                                    role="link"
                                    aria-label="<?= e(__('documents.view') . ': ' . (string) ($doc['title'] ?? '')) ?>"
                                >
                                    <td data-label="<?= e(__('documents.date')) ?>">
                                        <span class="documents-date"><?= e(format_date($docDate) ?: '—') ?></span>
                                        <?php if ($expires !== ''): ?>
                                            <span class="documents-expires<?= $expiresPast ? ' is-late' : ($expiresSoon ? ' is-soon' : '') ?>">
                                                <?= e($expiresPast
                                                    ? __('documents.expires_past', ['date' => format_date($expires) ?: $expires])
                                                    : __('documents.expires_on', ['date' => format_date($expires) ?: $expires])) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="<?= e(__('documents.title_field')) ?>">
                                        <div class="documents-title">
                                            <strong><?= e((string) ($doc['title'] ?? '')) ?></strong>
                                            <?php if ($hasFile): ?>
                                                <span class="documents-file-badge"><?= e(__('documents.file')) ?></span>
                                            <?php endif; ?>
                                            <?php if ($langLabel !== ''): ?>
                                                <span class="documents-lang"><?= e($langLabel) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php $displaySummary = document_summary_display((string) ($doc['summary'] ?? '')); ?>
                                        <?php if ($displaySummary !== ''): ?>
                                            <div class="muted documents-summary"><?= e($displaySummary) ?></div>
                                        <?php endif; ?>
                                    </td>

                                    <td data-label="<?= e(__('documents.number')) ?>"><?= e($docNumber !== '' ? $docNumber : '—') ?></td>
                                    <td data-label="<?= e(__('documents.filter_section')) ?>">
                                        <?php $docSection = (string) ($doc['source_section'] ?? 'documents'); ?>
                                        <span class="documents-section-badge"><?= e((string) ($sectionLabels[$docSection] ?? ucfirst(str_replace('_', ' ', $docSection)))) ?></span>
                                        <?php $docPeriod = document_period_label($doc); ?>
                                        <?php if ($docPeriod !== ''): ?><span class="documents-lang"><?= e($docPeriod) ?></span><?php endif; ?>
                                    </td>
                                    <td data-label="<?= e(__('documents.uploaded_by')) ?>">
                                        <?php
                                        $docUploader = trim((string) ($doc['uploaded_by_name'] ?? ''));
                                        if ($docUploader === '') {
                                            $docUploader = trim((string) ($doc['author_name'] ?? ''));
                                        }
                                        ?>
                                        <span class="documents-uploader"><?= e($docUploader !== '' ? $docUploader : '—') ?></span>
                                        <?php $docCreated = trim((string) ($doc['created_at'] ?? '')); ?>
                                        <?php if ($docCreated !== ''): ?>
                                            <span class="muted documents-summary"><?= e(format_datetime($docCreated)) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="<?= e(__('documents.status')) ?>">
                                        <span class="doc-status doc-status-<?= e($docStatus) ?>"><?= e(__('documents.status_' . $docStatus)) ?></span>
                                    </td>
                                    <td class="doc-row-actions" data-stop-row-nav>
                                        <?= row_actions([
                                            [
                                                'icon' => $hasFile ? 'file' : 'view',
                                                'label' => $hasFile ? __('documents.open_file') : __('documents.view'),
                                                'href' => $hasFile ? url('/documents/' . $docId . '/file') : $detailUrl,
                                                'target' => $hasFile ? '_blank' : null,
                                                'rel' => $hasFile ? 'noopener' : null,
                                                'primary' => $hasFile,
                                            ],
                                            [
                                                'icon' => 'download',
                                                'label' => __('documents.download_file'),
                                                'show' => $hasFile,
                                                'href' => $hasFile ? url('/documents/' . $docId . '/download') : '',
                                            ],
                                            [
                                                'icon' => 'edit',
                                                'label' => __('documents.edit'),
                                                'show' => $canManage,
                                                'href' => url('/documents/' . $docId . '/edit'),
                                            ],
                                        ]) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </<?= $groupTag ?>>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

