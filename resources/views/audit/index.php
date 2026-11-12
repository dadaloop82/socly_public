<?php
/** @var array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int} $result */
/** @var array{q:string,user_id:string,entity_type:string,date_from:string,date_to:string} $filters */
/** @var array{users:list<array{id:string,label:string}>,entities:list<string>} $options */
/** @var array{total:int,today:int,week:int,actors:int} $summary */
$filters = is_array($filters ?? null) ? $filters : [];
$filters += ['q' => '', 'user_id' => '', 'entity_type' => '', 'date_from' => '', 'date_to' => ''];
$result = is_array($result ?? null) ? $result : [];
$result += ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 50];
$summary = is_array($summary ?? null) ? $summary : [];
$summary += ['total' => 0, 'today' => 0, 'week' => 0, 'actors' => 0];
$options = is_array($options ?? null) ? $options : [];
$options += ['users' => [], 'entities' => []];
$page = (int) $result['page'];
$pages = (int) $result['pages'];
$rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
$hasFilters = trim($filters['q']) !== ''
    || $filters['user_id'] !== ''
    || $filters['entity_type'] !== ''
    || $filters['date_from'] !== ''
    || $filters['date_to'] !== '';

/** Translated action label with a technical fallback (never a missing key). */
$actionLabel = static function (string $action): string {
    $key = 'audit.actions.' . str_replace(['.', '-'], '_', $action);
    $label = (string) __($key);
    return $label === $key ? $action : $label;
};
$entityLabel = static function (string $type): string {
    $key = 'audit.entities.' . $type;
    $label = (string) __($key);
    return $label === $key ? $type : $label;
};
$prettyJson = static function (?string $json): string {
    $json = trim((string) $json);
    if ($json === '') {
        return '';
    }
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return $json;
    }
    return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
$pageUrl = static fn (int $target): string => url('/audit?' . http_build_query(array_filter(
    array_merge($filters, ['page' => $target > 1 ? $target : null]),
    static fn ($value): bool => $value !== '' && $value !== null
)));

$userOptions = [];
foreach (($options['users'] ?? []) as $userOption) {
    $optionId = (string) ($userOption['id'] ?? '');
    if ($optionId === '') {
        continue;
    }
    $userOptions[$optionId] = $optionId === 'system'
        ? (string) __('audit.system_actor')
        : (string) ($userOption['label'] ?? '');
}
$entityOptions = [];
foreach (($options['entities'] ?? []) as $entityType) {
    $entityType = (string) $entityType;
    if ($entityType !== '') {
        $entityOptions[$entityType] = $entityLabel($entityType);
    }
}
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('audit.title')) ?></h1>
        <p class="page-lede"><?= e(__('audit.lede')) ?></p>
    </div>
    <?php if (can('dashboard.view')): ?>
        <div class="actions">
            <a class="btn btn-ghost" href="<?= e(url('/dashboard')) ?>"><?= e(__('nav.back_to_dashboard')) ?></a>
        </div>
    <?php endif; ?>
</div>

<div class="stats stats-context-members members-summary-stats audit-summary">
    <div class="stat">
        <div class="label"><?= e(__('audit.summary_total')) ?></div>
        <div class="value"><?= (int) $summary['total'] ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('audit.summary_today')) ?></div>
        <div class="value"><?= (int) $summary['today'] ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('audit.summary_week')) ?></div>
        <div class="value"><?= (int) $summary['week'] ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('audit.summary_actors')) ?></div>
        <div class="value"><?= (int) $summary['actors'] ?></div>
    </div>
</div>

<form class="panel filter-bar members-filter audit-filter" method="get" action="<?= e(url('/audit')) ?>" role="search">
    <div class="members-filter-row">
        <div class="members-filter-q">
            <?= field('search', [
                'name' => 'q',
                'id' => 'audit-q',
                'label' => (string) __('audit.search'),
                'value' => (string) $filters['q'],
                'context' => 'filter',
                'placeholder' => (string) __('audit.search_placeholder'),
                'attrs' => ['enterkeyhint' => 'search', 'autocomplete' => 'off'],
            ]) ?>
        </div>
        <?= field('select', [
            'name' => 'user_id',
            'id' => 'audit-user',
            'label' => (string) __('audit.user'),
            'value' => (string) $filters['user_id'],
            'context' => 'filter',
            'empty_label' => (string) __('audit.user_all'),
            'options' => $userOptions,
        ]) ?>
        <?= field('select', [
            'name' => 'entity_type',
            'id' => 'audit-entity',
            'label' => (string) __('audit.entity'),
            'value' => (string) $filters['entity_type'],
            'context' => 'filter',
            'empty_label' => (string) __('audit.entity_all'),
            'options' => $entityOptions,
        ]) ?>
        <?= field('date', [
            'name' => 'date_from',
            'id' => 'audit-date-from',
            'label' => (string) __('audit.date_from'),
            'value' => (string) $filters['date_from'],
            'context' => 'filter',
        ]) ?>
        <?= field('date', [
            'name' => 'date_to',
            'id' => 'audit-date-to',
            'label' => (string) __('audit.date_to'),
            'value' => (string) $filters['date_to'],
            'context' => 'filter',
        ]) ?>
    </div>
    <div class="audit-filter-actions">
        <button class="btn btn-sm" type="submit"><?= e(__('audit.filter_submit')) ?></button>
        <?php if ($hasFilters): ?>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('/audit')) ?>"><?= e(__('audit.filter_reset')) ?></a>
        <?php endif; ?>
    </div>
</form>

<div class="panel audit-panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('audit.results')) ?></h2>
            <p class="section-lede"><?= e(__('audit.results_count', ['count' => (string) (int) $result['total']])) ?></p>
        </div>
    </div>
    <?php if ($rows === []): ?>
        <div class="empty-state">
            <strong><?= e(__('audit.empty_title')) ?></strong>
            <p><?= e(__('audit.empty_text')) ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap embedded">
            <table class="audit-table">
                <thead>
                <tr>
                    <th><?= e(__('audit.col_when')) ?></th>
                    <th><?= e(__('audit.col_who')) ?></th>
                    <th><?= e(__('audit.col_action')) ?></th>
                    <th><?= e(__('audit.col_entity')) ?></th>
                    <th><?= e(__('audit.col_ip')) ?></th>
                    <th><?= e(__('audit.col_details')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $rowId = (int) ($row['id'] ?? 0);
                    $when = trim((string) ($row['created_at'] ?? ''));
                    $action = trim((string) ($row['action'] ?? ''));
                    $entityType = trim((string) ($row['entity_type'] ?? ''));
                    $entityId = trim((string) ($row['entity_id'] ?? ''));
                    $actor = trim((string) ($row['actor'] ?? ''));
                    if ($actor === '') {
                        $actor = trim((string) ($row['user_id'] ?? '')) === ''
                            ? (string) __('audit.system_actor')
                            : (string) __('audit.unknown_actor');
                    }
                    $ip = trim((string) ($row['ip'] ?? ''));
                    $before = $prettyJson($row['before_json'] ?? null);
                    $after = $prettyJson($row['after_json'] ?? null);
                    ?>
                    <tr data-audit-row="<?= $rowId ?>">
                        <td data-label="<?= e(__('audit.col_when')) ?>">
                            <?php if ($when !== ''): ?>
                                <time datetime="<?= e($when) ?>"><?= e(format_datetime($when)) ?></time>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td data-label="<?= e(__('audit.col_who')) ?>"><?= e($actor) ?></td>
                        <td data-label="<?= e(__('audit.col_action')) ?>">
                            <span class="audit-action"><?= e($actionLabel($action)) ?></span>
                            <span class="audit-action-raw"><?= e($action) ?></span>
                        </td>
                        <td data-label="<?= e(__('audit.col_entity')) ?>">
                            <?= e($entityLabel($entityType)) ?>
                            <?php if ($entityId !== ''): ?>
                                <span class="audit-entity-id"><?= e(ctype_digit($entityId) ? '#' . $entityId : $entityId) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="<?= e(__('audit.col_ip')) ?>">
                            <?php if ($ip !== ''): ?><code class="audit-ip"><?= e($ip) ?></code><?php else: ?>—<?php endif; ?>
                        </td>
                        <td data-label="<?= e(__('audit.col_details')) ?>">
                            <?php if ($before !== '' || $after !== ''): ?>
                                <details class="audit-detail">
                                    <summary><?= e(__('audit.details_show')) ?></summary>
                                    <div class="audit-detail-body">
                                        <?php if ($before !== ''): ?>
                                            <div class="audit-detail-block">
                                                <h3><?= e(__('audit.before')) ?></h3>
                                                <pre><?= e($before) ?></pre>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($after !== ''): ?>
                                            <div class="audit-detail-block">
                                                <h3><?= e(__('audit.after')) ?></h3>
                                                <pre><?= e($after) ?></pre>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ($pages > 1): ?>
        <?php
        // Pager: window of pages around the current one + first/last (same pattern as the lists).
        $pageWindow = [1];
        for ($p = max(2, $page - 1); $p <= min($pages - 1, $page + 1); $p++) {
            $pageWindow[] = $p;
        }
        $pageWindow[] = $pages;
        $pageWindow = array_values(array_unique($pageWindow));
        sort($pageWindow);
        ?>
        <nav class="list-pager" aria-label="<?= e(__('common.pager_aria')) ?>">
            <?php if ($page > 1): ?>
                <a class="list-pager-step" href="<?= e($pageUrl($page - 1)) ?>" rel="prev">← <?= e(__('common.pager_prev')) ?></a>
            <?php else: ?>
                <span class="list-pager-step is-disabled">← <?= e(__('common.pager_prev')) ?></span>
            <?php endif; ?>
            <div class="list-pager-pages">
                <?php $previousPage = 0; foreach ($pageWindow as $pageNumber): ?>
                    <?php if ($previousPage > 0 && $pageNumber > $previousPage + 1): ?>
                        <span class="list-pager-gap" aria-hidden="true">…</span>
                    <?php endif; ?>
                    <a class="list-pager-page<?= $pageNumber === $page ? ' is-current' : '' ?>"
                       href="<?= e($pageUrl($pageNumber)) ?>"
                       <?= $pageNumber === $page ? 'aria-current="page"' : '' ?>><?= $pageNumber ?></a>
                    <?php $previousPage = $pageNumber; ?>
                <?php endforeach; ?>
            </div>
            <?php if ($page < $pages): ?>
                <a class="list-pager-step" href="<?= e($pageUrl($page + 1)) ?>" rel="next"><?= e(__('common.pager_next')) ?> →</a>
            <?php else: ?>
                <span class="list-pager-step is-disabled"><?= e(__('common.pager_next')) ?> →</span>
            <?php endif; ?>
            <span class="muted list-pager-info"><?= e(__('common.pager_info', ['page' => (string) $page, 'pages' => (string) $pages])) ?></span>
        </nav>
    <?php endif; ?>
</div>

