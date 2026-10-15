<?php
/**
 * Verbali e documenti — pagina elenco nello stile di Soci, Prima cassa e Scadenzario:
 * intestazione con azione primaria, KPI cliccabili, barra filtri live e archivio
 * raggruppato per categoria con righe cliccabili.
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
$hasActiveFilters = $query !== '' || $filterStatus !== '' || $filterCategory !== '' || $filterExpiring;

$categoryLabels = [];
foreach ($categories as $category) {
    $categoryLabels[(string) $category['key']] = (string) $category['label'];
}
$today = date('Y-m-d');
$soon = date('Y-m-d', strtotime('+30 days'));

// Keeps the other filters when one of them changes.
$filterUrl = static function (array $overrides = []) use ($query, $filterStatus, $filterCategory, $filterExpiring): string {
    $params = [];
    if ($query !== '') {
        $params['q'] = $query;
    }
    if ($filterStatus !== '') {
        $params['status'] = $filterStatus;
    }
    if ($filterCategory !== '') {
        $params['category'] = $filterCategory;
    }
    if ($filterExpiring) {
        $params['expiring'] = '1';
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
];

// Archivio raggruppato per categoria, nell'ordine delle categorie configurate.
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
            <a class="btn btn-ghost" href="<?= e($filterUrl(['q' => '', 'status' => '', 'category' => '', 'expiring' => false])) ?>"><?= e(__('documents.filter_reset')) ?></a>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <a class="btn" href="<?= e(url('/documents/create')) ?>"><?= e(__('documents.add')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="stats stats-context-documents">
    <a class="stat" href="<?= e($filterUrl(['q' => '', 'status' => '', 'category' => '', 'expiring' => false])) ?>">
        <div class="label"><?= e(__('documents.stats_total')) ?></div>
        <div class="value"><?= (int) ($counts['total'] ?? 0) ?></div>
    </a>
    <a class="stat<?= $filterStatus === 'draft' ? ' is-active' : '' ?>" href="<?= e($filterUrl(['status' => $filterStatus === 'draft' ? '' : 'draft', 'expiring' => false])) ?>">
        <div class="label"><?= e(__('documents.status_draft')) ?></div>
        <div class="value"><?= (int) ($counts['draft'] ?? 0) ?></div>
    </a>
    <a class="stat<?= $filterStatus === 'approved' ? ' is-active' : '' ?>" href="<?= e($filterUrl(['status' => $filterStatus === 'approved' ? '' : 'approved', 'expiring' => false])) ?>">
        <div class="label"><?= e(__('documents.status_approved')) ?></div>
        <div class="value"><?= (int) ($counts['approved'] ?? 0) ?></div>
    </a>
    <a class="stat<?= $filterExpiring ? ' is-active' : '' ?>" href="<?= e($filterUrl(['expiring' => $filterExpiring ? false : '1'])) ?>">
        <div class="label"><?= e(__('documents.stats_expiring')) ?></div>
        <div class="value<?= (int) ($counts['expiring'] ?? 0) > 0 ? ' stat-negative' : '' ?>"><?= (int) ($counts['expiring'] ?? 0) ?></div>
    </a>
</div>

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
            'value' => $filterStatus,
            'context' => 'filter',
            'empty_label' => (string) __('documents.filter_all'),
            'options' => $statusFilterOptions,
        ]) ?>
        <?= field('checkbox', [
            'name' => 'expiring',
            'id' => 'documents-expiring-filter',
            'label' => (string) __('documents.stats_expiring'),
            'value' => $filterExpiring ? '1' : '',
            'check_value' => '1',
            'context' => 'filter',
            'wrapper_class' => 'documents-filter-expiring',
        ]) ?>
        <?php if ($hasActiveFilters): ?>
            <a class="btn btn-ghost btn-sm documents-filter-reset" href="<?= e($filterUrl(['q' => '', 'status' => '', 'category' => '', 'expiring' => false])) ?>"><?= e(__('documents.filter_reset')) ?></a>
        <?php endif; ?>
    </div>
</form>
<?php endif; ?>


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
                <section class="doc-archive-group">
                    <header class="doc-archive-group-head">
                        <h3 class="doc-archive-group-title"><?= e($categoryLabels[$groupKey] ?? $groupKey) ?></h3>
                        <span class="doc-archive-group-count"><?= count($groupItems) ?></span>
                    </header>
                    <div class="table-wrap embedded">
                        <table class="documents-table">
                            <thead>
                            <tr>
                                <th><?= e(__('documents.date')) ?></th>
                                <th><?= e(__('documents.title_field')) ?></th>
                                <th><?= e(__('documents.number')) ?></th>
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
                                        <?php if ($summary !== ''): ?>
                                            <div class="muted documents-summary"><?= e($summary) ?></div>
                                        <?php endif; ?>
                                    </td>

                                    <td data-label="<?= e(__('documents.number')) ?>"><?= e($docNumber !== '' ? $docNumber : '—') ?></td>
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
                                                'icon' => 'external',
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
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

