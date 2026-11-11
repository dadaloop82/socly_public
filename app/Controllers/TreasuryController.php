<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\BudgetService;
use Socly\Services\ComponentService;
use Socly\Services\CurrencyService;
use Socly\Services\MemberService;
use Socly\Services\RecurringService;
use Socly\Services\TreasuryService;
use Socly\Services\VersionService;

final class TreasuryController extends BaseController
{
    public function __construct(
        View $view,
        private readonly TreasuryService $treasury,
        private readonly MemberService $members,
        private readonly ComponentService $components,
        private readonly CurrencyService $currency,
        private readonly BudgetService $budgets,
        private readonly VersionService $versions,
        private readonly RecurringService $recurring
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        require_component('treasury');
        $this->runRecurringDue($request);
        $query = trim((string) $request->input('q', ''));
        if (mb_strlen($query) > 120) {
            $query = mb_substr($query, 0, 120);
        }
        $groupByCategory = (string) $request->input('group', '1') !== '0';
        $sort = (string) $request->input('sort', 'date_desc');
        if (!in_array($sort, ['date_desc', 'date_asc', 'created_desc', 'invoice_desc'], true)) {
            $sort = 'date_desc';
        }
        $filters = $this->ledgerFilters($request);
        // Paged ledger: 50 rows per page keeps the first note fast on large registers.
        $perPage = 50;
        $totalRows = $this->treasury->ledgerTotal($query, $filters);
        $pages = max(1, (int) ceil($totalRows / $perPage));
        $page = min($pages, max(1, (int) $request->input('page', 1)));
        $offset = ($page - 1) * $perPage;
        $ledger = $this->treasury->ledger($perPage, $query, $sort, $filters, $offset);
        $config = $this->components->config('treasury', ['auto_from_payments' => true]);
        // Only the enabled wallets (+ deactivated ones with movements) are listed.
        $wallets = $this->treasury->overviewWallets();
        $this->render('treasury/index', [
            'title' => __('treasury.title'),
            'ledger' => $ledger,
            'movement_groups' => $groupByCategory
                ? $this->treasury->groupedByCategory($perPage, $query, $sort, $filters, $offset)
                : [[
                    'key' => 'all',
                    'label' => __('treasury.group_by_category_off'),
                    'items' => $ledger['movements'],
                ]],
            'group_by_category' => $groupByCategory,
            'ledger_sort' => $sort,
            'filters' => $filters,
            'config' => $config,
            'members' => $this->members->listForSelect(),
            'categories' => $this->treasury->categoryOptions(),
            'category_groups' => $this->treasury->categoryGroups(),
            'accounts' => $wallets['accounts'],
            'account_balances' => $wallets['balances'],
            'payment_method_groups' => $this->treasury->paymentMethodGroups(),
            'default_category' => $this->treasury->defaultCategory(),
            'search_query' => $query,
            'beneficiaries' => $this->treasury->beneficiaries(),
            'currency' => $this->currency,
            'hasAnyMovements' => $this->treasury->movementCount() > 0,
            'runts_filter_groups' => $this->runtsFilterGroups(),
            'budget_filter_options' => $this->treasury->budgetItemFilterOptions(),
            'advanced_filters_count' => $this->advancedFilterCount($filters),
            'pending_count' => $this->treasury->pendingCount(),
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'total_rows' => $totalRows,
            'recurring_rules' => $this->recurring->all(),
        ]);
    }

    /**
     * Genera i movimenti delle operazioni ricorrenti scadute (al massimo ogni 15 minuti,
     * così la prima nota non paga una query a ogni caricamento).
     */
    private function runRecurringDue(Request $request): void
    {
        try {
            $marker = storage_path('cache/recurring_run.txt');
            $last = is_file($marker) ? (int) filemtime($marker) : 0;
            if ($last > 0 && (time() - $last) < 900) {
                return;
            }
            @touch($marker);
            $this->recurring->runDue($request->ip(), auth_user()['id'] ?? null);
        } catch (\Throwable) {
            // una regola rotta non deve bloccare la prima nota
        }
    }

    /** Sospende o riattiva un'operazione ricorrente. */
    public function toggleRecurring(Request $request, string $id): void
    {
        require_component('treasury');
        if (!can('treasury.manage')) {
            http_response_code(403);
            $this->render('errors/403');
            return;
        }
        $rule = $this->recurring->find((int) $id);
        if ($rule !== null) {
            $this->recurring->setActive((int) $id, empty($rule['is_active']), $request->ip());
            $this->flash('success', empty($rule['is_active']) ? __('treasury.recurring_resumed') : __('treasury.recurring_paused'));
        }
        redirect('/treasury');
    }

    public function deleteRecurring(Request $request, string $id): void
    {
        require_component('treasury');
        if (!can('treasury.manage')) {
            http_response_code(403);
            $this->render('errors/403');
            return;
        }
        $this->recurring->delete((int) $id, $request->ip());
        $this->flash('success', __('treasury.recurring_deleted'));
        redirect('/treasury');
    }

    public function create(Request $request): void
    {
        require_component('treasury');
        if ((string) $request->input('retry', '') !== '1') {
            $this->clearOld();
        }
        $this->render('treasury/form', [
            'title' => __('treasury.add_movement'),
            'isEdit' => false,
            'action' => url('/treasury'),
            'movement' => null,
            'default_category' => $this->treasury->defaultCategory(),
            'categories' => $this->treasury->categoryOptions(),
            'category_groups' => $this->treasury->categoryGroups(),
            'accounts' => $this->treasury->accountOptions(),
            'account_balances' => $this->treasury->accountBalances(),
            'payment_method_groups' => $this->treasury->paymentMethodGroups(),
            'members' => $this->members->listForSelect(),
            'config' => $this->components->config('treasury', ['auto_from_payments' => true]),
            'beneficiaries' => $this->treasury->beneficiaries(),
            'currency' => $this->currency,
            'budget_items' => $this->budgets->itemOptions((int) date('Y')),
        ]);
    }

    public function edit(Request $request, string $id): void
    {
        require_component('treasury');
        if ((string) $request->input('retry', '') !== '1') {
            $this->clearOld();
        }
        $movement = $this->treasury->find((int) $id);
        if ($movement === null) {
            http_response_code(404);
            $this->flash('errors', ['treasury' => __('errors.404')]);
            redirect('/treasury');
        }
        $categories = $this->treasury->categoryOptions();
        $catKey = trim((string) ($movement['category'] ?? ''));
        if ($catKey !== '' && !in_array($catKey, array_column($categories, 'key'), true)) {
            $categories[] = [
                'key' => $catKey,
                'label' => $this->treasury->categoryLabel($catKey),
                'builtin' => false,
            ];
        }
        $conflictVersion = (int) $request->input('v', 0);
        $conflictActive = (string) $request->input('conflict', '') !== '' && $conflictVersion > 0;
        $conflict = null;
        if ($conflictActive) {
            $editor = $this->versions->lastEditor('treasury', (int) $movement['id']);
            $conflict = [
                'user' => $editor['name'],
                'at' => $editor['at'],
                'current' => $conflictVersion,
                'reload_url' => url('/treasury/' . (int) $movement['id'] . '/edit'),
            ];
        }
        $this->render('treasury/form', [
            'title' => __('treasury.edit'),
            'isEdit' => true,
            'action' => url('/treasury/' . (int) $movement['id']),
            'movement' => $movement,
            'movement_version' => $conflictActive ? $conflictVersion : (int) ($movement['row_version'] ?? 0),
            'movement_conflict' => $conflict,
            'default_category' => (string) ($movement['category'] ?? 'membership_fee'),
            'categories' => $categories,
            'category_groups' => $this->treasury->categoryGroups(),
            'accounts' => $this->treasury->accountOptions(),
            'account_balances' => $this->treasury->accountBalances(),
            'payment_method_groups' => $this->treasury->paymentMethodGroups(),
            'members' => $this->members->listForSelect(),
            'config' => $this->components->config('treasury', ['auto_from_payments' => true]),
            'beneficiaries' => $this->treasury->beneficiaries(),
            'currency' => $this->currency,
            'budget_items' => $this->budgets->itemOptions((int) substr((string) ($movement['movement_date'] ?? date('Y-m-d')), 0, 4)),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        require_component('treasury');
        if (upload_post_too_large()) {
            $this->flash('errors', [
                'invoice_pdf' => __('documents.upload_too_large', ['max' => upload_max_mb()]),
            ]);
            $this->rememberOld($request->all());
            redirect('/treasury/' . (int) $id . '/edit?retry=1');
        }
        $posted = $request->all();
        $result = $this->treasury->update(
            (int) $id,
            $posted,
            $request->file('invoice_pdf'),
            $request->ip(),
            auth_user()['id'] ?? null,
            (int) ($posted['row_version'] ?? 0),
            !empty($posted['force'])
        );
        if (empty($result['ok'])) {
            $this->rememberOld($posted);
            if (!empty($result['conflict'])) {
                $this->flash('errors', ['concurrency' => __('concurrency.conflict_flash', [
                    'user' => (string) ($result['editor']['name'] ?? ''),
                    'at' => (string) ($result['editor']['at'] ?? ''),
                ])]);
                redirect('/treasury/' . (int) $id . '/edit?retry=1&conflict=1&v=' . (int) ($result['current_version'] ?? 0));
            }
            $this->flash('errors', $result['errors'] ?? []);
            redirect('/treasury/' . (int) $id . '/edit?retry=1');
        }
        $this->clearOld();
        $this->flash('success', __('treasury.updated'));
        redirect('/treasury');
    }

    public function store(Request $request): void
    {
        require_component('treasury');
        if (upload_post_too_large()) {
            $this->flash('errors', [
                'invoice_pdf' => __('documents.upload_too_large', ['max' => upload_max_mb()]),
            ]);
            $this->rememberOld($request->all());
            redirect('/treasury/create?retry=1');
        }
        $result = $this->treasury->create(
            $request->all(),
            $request->file('invoice_pdf'),
            $request->ip(),
            auth_user()['id'] ?? null
        );
        if (empty($result['ok'])) {
            $this->flash('errors', $result['errors'] ?? ['treasury' => __('validation.required')]);
            $this->rememberOld($request->all());
            redirect('/treasury/create?retry=1');
        }
        // Operazione ricorrente: la regola parte dalla data del movimento appena salvato.
        if (!empty($request->input('recurring'))) {
            try {
                $this->recurring->createFromInput(
                    $request->all(),
                    (string) ($request->input('movement_date', date('Y-m-d'))),
                    auth_user()['id'] ?? null,
                    $request->ip()
                );
            } catch (\Throwable) {
                $this->flash('errors', ['recurring' => __('treasury.recurring_create_failed')]);
            }
        }
        $this->clearOld();
        $this->flash('success', __('treasury.saved'));
        redirect('/treasury');
    }

    /**
     * Movimenti simili al corrente: usato dal pannello “applica anche agli altri”.
     */
    public function similar(Request $request): void
    {
        require_component('treasury');
        if (!can('treasury.manage')) {
            $this->jsonResponse(['ok' => false, 'error' => __('errors.403')], 403);
            return;
        }
        $data = $request->all();
        // Campi che l'utente ha davvero modificato (inviati dal client): quelli non toccati non
        // filtrano i simili e non compaiono nel riepilogo.
        $fieldsToApply = [];
        $rawApplying = $data['applying'] ?? null;
        if (is_array($rawApplying)) {
            foreach ($rawApplying as $field) {
                if (in_array($field, ['category', 'runts_line', 'budget_item_id'], true)) {
                    $fieldsToApply[$field] = (string) $field;
                }
            }
            $fieldsToApply = array_values($fieldsToApply);
        }
        if ($fieldsToApply === []) {
            $fieldsToApply = array_values(array_filter([
                trim((string) ($data['category'] ?? '')) !== '' && (string) $data['category'] !== '__new__' ? 'category' : '',
                trim((string) ($data['runts_line'] ?? '')) !== '' ? 'runts_line' : '',
                    (int) ($data['budget_item_id'] ?? 0) > 0 ? 'budget_item_id' : '',
            ]));
        }

        $result = $this->treasury->similarMovements([
            'direction' => (string) ($data['direction'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'beneficiary' => (string) ($data['beneficiary'] ?? ''),
        ], 3, (int) ($data['exclude_id'] ?? 0), $fieldsToApply, $this->originals($data));

        $this->jsonResponse([
            'ok' => true,
            'total' => (int) $result['total'],
            'skipped' => (int) ($result['skipped'] ?? 0),
            'ids' => $result['ids'],
            'matches' => $result['matches'],
            'changes' => $this->describeClassification($data, $fieldsToApply),
        ]);
    }

    /** Applica tipologia / voce Modello D / centro di costo ai movimenti simili scelti. */
    public function applySimilar(Request $request): void
    {
        require_component('treasury');
        if (!can('treasury.manage')) {
            $this->jsonResponse(['ok' => false, 'error' => __('errors.403')], 403);
            return;
        }
        $data = $request->all();
        $rawIds = $data['ids'] ?? [];
        $ids = [];
        if (is_array($rawIds)) {
            foreach ($rawIds as $value) {
                $id = (int) $value;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        if ($ids === []) {
            $this->jsonResponse(['ok' => false, 'error' => __('treasury.similar_none_selected')], 422);
            return;
        }
        $updated = $this->treasury->applyClassification(array_values($ids), [
            'category' => (string) ($data['category'] ?? ''),
            'runts_line' => (string) ($data['runts_line'] ?? ''),
            'budget_item_id' => (string) ($data['budget_item_id'] ?? ''),
        ], $request->ip(), $this->originals($data));

        $this->jsonResponse([
            'ok' => true,
            'updated' => $updated,
            'message' => (string) __('treasury.similar_applied', ['count' => (string) $updated]),
        ]);
    }

    /**
     * Valori originali del record (inviati dal form): servono a capire quali valori sono
     * “automatici” e quindi ancora aggiornabili.
     *
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function originals(array $data): array
    {
        return [
            'category' => trim((string) ($data['original_category'] ?? '')),
            'runts_line' => strtoupper(trim((string) ($data['original_runts_line'] ?? ''))),
            'budget_item_id' => (string) (int) ($data['original_budget_item_id'] ?? 0),
        ];
    }

    /**
     * Riepilogo leggibile delle modifiche che verranno applicate.
     *
     * @param array<string,mixed> $data
     * @return list<array{field:string,label:string,value:string}>
     */
    private function describeClassification(array $data, array $only = []): array
    {
        $changes = [];
        $wanted = static fn (string $field): bool => $only === [] || in_array($field, $only, true);
        $category = trim((string) ($data['category'] ?? ''));
        if ($category !== '' && $category !== '__new__' && $wanted('category')) {
            $changes[] = ['field' => 'category', 'label' => (string) __('treasury.category'), 'value' => $this->treasury->categoryLabel($category)];
        }
        $runtsLine = strtoupper(trim((string) ($data['runts_line'] ?? '')));
        if ($runtsLine !== '' && $wanted('runts_line')) {
            $changes[] = ['field' => 'runts_line', 'label' => (string) __('runts.line_label'), 'value' => \Socly\Services\RuntsReportService::lineLabel($runtsLine)];
        }
        $budgetItemId = (int) ($data['budget_item_id'] ?? 0);
        if ($budgetItemId > 0 && $wanted('budget_item_id')) {
            $budgetLabel = $this->treasury->budgetItemLabel($budgetItemId);
            if ($budgetLabel !== '') {
                $changes[] = ['field' => 'budget_item_id', 'label' => (string) __('treasury.budget_item'), 'value' => $budgetLabel];
            }
        }

        return $changes;
    }

    /** Approve a pending first-note movement (only approved movements count). */
    public function approve(Request $request, string $id): void
    {
        require_component('treasury');
        if (!can('treasury.manage')) {
            http_response_code(403);
            $this->render('errors/403');
            return;
        }
        $result = $this->treasury->approve((int) $id, $request->ip(), auth_user()['id'] ?? null);
        $ok = !empty($result['ok']);
        if ((string) $request->input('ajax', '') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => $ok,
                'id' => (int) $id,
                'message' => $ok ? (string) __('treasury.approved') : (string) ($result['message'] ?? __('validation.required')),
                'totals' => $ok ? $this->totalsPayload() : null,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!$ok) {
            $this->flash('errors', [(string) ($result['message'] ?? __('validation.required'))]);
        } else {
            $this->flash('success', __('treasury.approved'));
        }
        redirect('/treasury');
    }

    /** Bulk actions on the first note: approve or delete the selected movements. */
    public function bulk(Request $request): void
    {
        require_component('treasury');
        if (!can('treasury.manage')) {
            http_response_code(403);
            $this->render('errors/403');
            return;
        }
        $raw = $request->input('movement_ids', []);
        $ids = [];
        if (is_array($raw)) {
            foreach ($raw as $value) {
                $id = (int) $value;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        if ($ids === []) {
            if ((string) $request->input('ajax', '') === '1') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => (string) __('treasury.bulk_none')], JSON_UNESCAPED_UNICODE);
                return;
            }
            $this->flash('errors', ['treasury' => __('treasury.bulk_none')]);
            redirect('/treasury');
        }
        $action = (string) $request->input('action', '');
        $isAjax = (string) $request->input('ajax', '') === '1';
        if ($action === 'approve') {
            $count = $this->treasury->approveMany(array_values($ids), $request->ip(), auth_user()['id'] ?? null);
        } elseif ($action === 'delete') {
            $count = $this->treasury->deleteMany(array_values($ids), $request->ip());
        } else {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => (string) __('validation.required')], JSON_UNESCAPED_UNICODE);
                return;
            }
            $this->flash('errors', ['treasury' => __('validation.required')]);
            redirect('/treasury');
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'action' => $action,
                'count' => $count,
                'ids' => array_values($ids),
                'totals' => $action === 'approve' ? $this->totalsPayload() : null,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($action === 'approve') {
            $this->flash('success', __('treasury.bulk_approved', ['count' => (string) $count]));
        } else {
            $this->flash('success', __('treasury.bulk_deleted', ['count' => (string) $count]));
        }
        redirect('/treasury');
    }

    /**
     * Totali aggiornati (saldo, entrate, uscite, da approvare) per l'aggiornamento in place
     * dopo un'approvazione: la pagina non deve ricaricarsi per mostrarli.
     *
     * @return array{balance:string,income:string,expense:string,balance_negative:bool,pending:int}
     */
    private function totalsPayload(): array
    {
        $ledger = $this->treasury->ledger(1);
        return [
            'balance' => $this->currency->format((float) $ledger['balance']),
            'income' => $this->currency->format((float) $ledger['income']),
            'expense' => $this->currency->format((float) $ledger['expense']),
            'balance_negative' => (float) $ledger['balance'] < 0,
            'pending' => $this->treasury->pendingCount(),
        ];
    }

    /**
     * Live wallet balances for the movement form (the "Da conto" select).
     */
    public function accountBalances(Request $request): void
    {
        require_component('treasury');
        $accounts = [];
        foreach ($this->treasury->accountBalances() as $row) {
            $accounts[(string) $row['key']] = [
                'label' => (string) $row['label'],
                'active' => (bool) $row['active'],
                'balance' => (float) $row['balance'],
                'formatted' => $this->currency->format((float) $row['balance']),
            ];
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'accounts' => $accounts], JSON_UNESCAPED_UNICODE);
    }

    public function attachment(Request $request, string $id): void
    {
        require_component('treasury');
        $path = $this->treasury->attachmentFilePath((int) $id);
        if ($path === null) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        $detected = mime_content_type($path);
        $mime = is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($path) . '"');
        readfile($path);
    }

    /**
     * Ledger filters from the query string (validated; period presets expand to a date range).
     *
     * @return array{direction:string,category:string,account:string,from:string,to:string,period:string}
     */
    /**
     * Voci del Modello D raggruppate (Uscite / Entrate) per il filtro avanzato.
     *
     * @return list<array{label:string,options:list<array{value:string,label:string}>}>
     */
    private function runtsFilterGroups(): array
    {
        $groups = [];
        foreach (['expense' => 'runts.expenses', 'income' => 'runts.incomes'] as $direction => $headingKey) {
            foreach (\Socly\Services\RuntsReportService::selectGroups($direction) as $group) {
                $groups[] = [
                    'label' => (string) __($headingKey) . ' · ' . (string) ($group['label'] ?? ''),
                    'options' => $group['options'] ?? [],
                ];
            }
        }

        return $groups;
    }

    /** Quanti filtri “avanzati” sono attivi (mostrato sul bottone). */
    private function advancedFilterCount(array $filters): int
    {
        $count = 0;
        foreach (['category', 'runts_line', 'budget_item_id', 'account', 'approval'] as $key) {
            if (trim((string) ($filters[$key] ?? '')) !== '') {
                $count++;
            }
        }

        return $count;
    }

    private function ledgerFilters(Request $request): array
    {
        $direction = (string) $request->input('direction', '');
        if (!in_array($direction, TreasuryService::DIRECTIONS, true)) {
            $direction = '';
        }
        $account = \Socly\Services\TreasuryService::normalizeAccount((string) $request->input('account', ''));
        if ($account !== '' && !$this->treasury->accountExists($account)) {
            $account = '';
        }
        $category = trim((string) $request->input('category', ''));
        if ($category !== '' && $category !== '__none__' && !in_array($category, array_column($this->treasury->categoryOptions(), 'key'), true)) {
            $category = '';
        }
        $runtsLine = strtoupper(trim((string) $request->input('runts_line', '')));
        if ($runtsLine !== '' && $runtsLine !== '__NONE__' && !\Socly\Services\RuntsReportService::isKnownLine($runtsLine, 'expense')
            && !\Socly\Services\RuntsReportService::isKnownLine($runtsLine, 'income')) {
            $runtsLine = '';
        }
        if ($runtsLine === '__NONE__') {
            $runtsLine = '__none__';
        }
        $budgetItem = trim((string) $request->input('budget_item_id', ''));
        if ($budgetItem !== '__none__' && (int) $budgetItem <= 0) {
            $budgetItem = '';
        }
        $period = (string) $request->input('period', '');
        if (!in_array($period, ['month', 'year', 'days30'], true)) {
            $period = '';
        }
        $approval = (string) $request->input('approval', '');
        if (!in_array($approval, ['pending', 'approved'], true)) {
            $approval = '';
        }
        $from = $this->isoDate((string) $request->input('from', ''));
        $to = $this->isoDate((string) $request->input('to', ''));
        if ($from === '' && $to === '' && $period !== '') {
            [$from, $to] = match ($period) {
                'month' => [date('Y-m-01'), date('Y-m-t')],
                'year' => [date('Y-01-01'), date('Y-12-31')],
                default => [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
            };
        }
        return [
            'direction' => $direction,
            'category' => $category,
            'runts_line' => $runtsLine,
            'budget_item_id' => $budgetItem,
            'account' => $account,
            'from' => $from,
            'to' => $to,
            'period' => $period,
            'approval' => $approval,
        ];
    }

    private function isoDate(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }
}
