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


declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\BudgetService;
use Socly\Services\ComponentService;
use Socly\Services\CurrencyService;
use Socly\Services\FileRepositoryService;
use Socly\Services\MemberService;
use Socly\Services\RecurringService;
use Socly\Services\TreasuryService;
use Socly\Services\VersionService;

final class TreasuryController extends BaseController
{
    /** Source section used by the bank-statement add-on for the excerpt images. */
    private const STATEMENT_SOURCE_SECTION = 'bank_statement_import';

    public function __construct(
        View $view,
        private readonly TreasuryService $treasury,
        private readonly MemberService $members,
        private readonly ComponentService $components,
        private readonly CurrencyService $currency,
        private readonly BudgetService $budgets,
        private readonly VersionService $versions,
        private readonly RecurringService $recurring,
        private readonly FileRepositoryService $files
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
        // Grouping by category is a reading preference: it starts switched off.
        $groupByCategory = (string) $request->input('group', '0') === '1';
        $sort = (string) $request->input('sort', 'date_desc');
        if (!in_array($sort, TreasuryService::SORTS, true)) {
            $sort = 'date_desc';
        }
        $filters = $this->ledgerFilters($request);
        // Endless ledger: five rows per batch, appended while scrolling (the pager stays as a
        // no-JS fallback and is hidden by the feed).
        $perPage = 5;
        $totalRows = $this->treasury->ledgerTotal($query, $filters);
        $pages = max(1, (int) ceil($totalRows / $perPage));
        $page = min($pages, max(1, (int) $request->input('page', 1)));
        $offset = ($page - 1) * $perPage;
        $ledger = $this->treasury->ledger($perPage, $query, $sort, $filters, $offset);
        $config = $this->components->config('treasury', ['auto_from_payments' => true]);
        // After a classified save: “apply to similar movements” proposal calculated server-side (no JS, no
        // card reload).
        $similarProbe = $this->similarProbe((int) $request->input('similar', 0));
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
            // Ready-made macro-groups (income / expense / financial / custom / other) for the bulk
            // classification sheet: the same list the movement form shows, without “create new”.
            'bulk_category_groups' => $this->treasury->categorySelectGroups(false),
            'accounts' => $wallets['accounts'],
            'account_balances' => $wallets['balances'],
            'payment_method_groups' => $this->treasury->paymentMethodGroups(),
            'default_category' => $this->treasury->defaultCategory(),
            'search_query' => $query,
            'beneficiaries' => $this->treasury->beneficiaries(),
            'currency' => $this->currency,
            'hasAnyMovements' => $this->treasury->movementCount() > 0,
            'runts_filter_groups' => $this->runtsFilterGroups(),
            'budget_filter_groups' => $this->treasury->budgetItemFilterGroups(),
            'filter_years' => $this->treasury->movementYears(),
            'advanced_filters_count' => $this->advancedFilterCount($filters),
            'pending_count' => $this->treasury->pendingCount(),
            'row_anomalies' => $this->treasury->ledgerAnomaliesById(
                array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $ledger['movements'])
            ),
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'total_rows' => $totalRows,
            'recurring_rules' => $this->recurring->all(),
            'similar_probe' => $similarProbe,
        ]);
    }

    /**
     * Generates entries for expired recurring operations (at most every 15 minutes, so the journal
     * entry doesn't pay a query for each load).
     */
    private function runRecurringDue(Request $request): void
    {
        try {
            $userId = auth_user()['id'] ?? null;
            $this->recurring->runDueThrottled(900, $request->ip(), $userId !== null ? (int) $userId : null);
        } catch (\Throwable) {
            // a broken rule does not have to block the first note
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
        $returnPath = $this->listReturnPath($request);
        // The loan/deposit registers open the form already on their category (and on the right
        // direction: money received vs money paid back).
        $categories = $this->treasury->categoryOptions();
        $requestedCategory = trim((string) $request->input('category', ''));
        $defaultCategory = $this->treasury->defaultCategory();
        foreach ($categories as $categoryOption) {
            if ((string) ($categoryOption['key'] ?? '') === $requestedCategory) {
                $defaultCategory = $requestedCategory;
                break;
            }
        }
        $requestedDirection = (string) $request->input('direction', '');
        $defaultDirection = in_array($requestedDirection, ['income', 'expense', 'transfer'], true)
            ? $requestedDirection
            : 'income';
        $this->render('treasury/form', [
            'title' => __('treasury.add_movement'),
            'isEdit' => false,
            'action' => url('/treasury'),
            'return_url' => url($returnPath),
            'return_path' => $returnPath,
            'movement' => null,
            'default_category' => $defaultCategory,
            'default_direction' => $defaultDirection,
            'categories' => $categories,
            'category_groups' => $this->treasury->categoryGroups(),
            'accounts' => $this->treasury->accountOptions(),
            'account_balances' => $this->treasury->accountBalances(),
            'payment_method_groups' => $this->treasury->paymentMethodGroups(),
            'members' => $this->members->listForSelect(),
            'config' => $this->components->config('treasury', ['auto_from_payments' => true]),
            'beneficiaries' => $this->treasury->beneficiaries(),
            'currency' => $this->currency,
            'budget_items' => $this->budgets->itemOptionsForMovement((int) date('Y')),
            'repository_documents' => $this->files->listForPicker('treasury', '', null, 80),
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
        $returnPath = $this->listReturnPath($request);
        // Quick edit inside an add-on dialog (`embed=1`): bare layout without the panel chrome and a
        // “saved” marker so the parent page can refresh its data when the operator closes the popup.
        $embed = trim((string) $request->input('embed', '')) === '1';
        $embedSaved = $embed && (string) $request->input('saved', '') === '1';
        // The previous/next arrows follow the ledger the operator came from: same sort, filters,
        // search and grouping — not a fixed date order.
        $returnQuery = $this->listReturnQuery($request);
        $listSort = (string) ($returnQuery['sort'] ?? 'date_desc');
        if (!in_array($listSort, TreasuryService::SORTS, true)) {
            $listSort = 'date_desc';
        }
        $listRequest = new Request('GET', '/treasury', $returnQuery, [], [], []);
        $adjacent = $this->treasury->adjacentMovements(
            (int) $movement['id'],
            $listSort,
            $this->ledgerFilters($listRequest),
            (string) ($returnQuery['q'] ?? ''),
            ($returnQuery['group'] ?? '') === '1'
        );
        $conflict = null;
        if ($conflictActive) {
            $editor = $this->versions->lastEditor('treasury', (int) $movement['id']);
            $conflict = [
                'user' => $editor['name'],
                'at' => $editor['at'],
                'current' => $conflictVersion,
                'reload_url' => url(self::withQuery('/treasury/' . (int) $movement['id'] . '/edit', ['ret' => $returnPath])),
            ];
        }
        $this->render('treasury/form', [
            'title' => __('treasury.edit'),
            'isEdit' => true,
            'action' => url('/treasury/' . (int) $movement['id']),
            'return_url' => url($returnPath),
            'return_path' => $returnPath,
            'movement' => $movement,
            'movement_version' => $conflictActive ? $conflictVersion : (int) ($movement['row_version'] ?? 0),
            'movement_conflict' => $conflict,
            // “Just created/modified by …” (last 15 minutes): the form refreshes it by polling.
            'movement_activity' => $this->versions->recentActivity('treasury', (int) $movement['id'], 15),
            'adjacent' => $adjacent,
            // “Similar movements” proposal (after an update that changed the classification): it is
            // rendered above the form so the operator can stay on the card.
            'similar_probe' => $this->similarProbe((int) $request->input('similar', 0)),
            'just_saved' => (string) flash('treasury_saved') === (string) (int) ($movement['id'] ?? 0),
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
            'budget_items' => $this->budgets->itemOptionsForMovement(
                (int) substr((string) ($movement['movement_date'] ?? date('Y-m-d')), 0, 4),
                (int) ($movement['budget_item_id'] ?? 0)
            ),
            'pattern_profile' => $this->treasury->classificationProfile($movement, (int) $movement['id'], 24),
            'embed' => $embed,
            'embed_saved' => $embedSaved,
            'repository_documents' => $this->files->listForPicker('treasury', '', null, 80),
            'attachment_name' => (int) ($movement['document_id'] ?? 0) > 0
                ? (string) (($this->files->fileInfo((int) $movement['document_id']) ?? [])['original_name'] ?? '')
                : '',
            'statement_crops' => $this->files->linkedDocumentsForRefs(
                'treasury',
                ['movement:' . (int) $movement['id']],
                self::STATEMENT_SOURCE_SECTION
            )['movement:' . (int) $movement['id']] ?? [],
            // Same ledger anomalies as the list warning mark: shown next to the relevant fields.
            'movement_anomalies' => $this->treasury->ledgerAnomaliesById([(int) $movement['id']])[(int) $movement['id']] ?? [],
        ], $embed ? 'layouts/embed' : 'layouts/app');
    }

    public function copy(Request $request, string $id): void
    {
        require_component('treasury');
        $this->clearOld();
        $movement = $this->treasury->find((int) $id);
        if ($movement === null) {
            http_response_code(404);
            $this->flash('errors', ['treasury' => __('errors.404')]);
            redirect('/treasury');
        }
        $returnPath = $this->listReturnPath($request);
        $categories = $this->treasury->categoryOptions();
        $catKey = trim((string) ($movement['category'] ?? ''));
        if ($catKey !== '' && !in_array($catKey, array_column($categories, 'key'), true)) {
            $categories[] = [
                'key' => $catKey,
                'label' => $this->treasury->categoryLabel($catKey),
                'builtin' => false,
            ];
        }
        // A copy is a new movement dated today: the original document/attachment is never carried.
        $copy = $movement;
        $copy['id'] = 0;
        $copy['movement_date'] = date('Y-m-d');
        $copy['attachment_path'] = '';
        $copy['document_id'] = null;
        $copy['payment_id'] = null;
        $direction = (string) ($movement['direction'] ?? '');
        $this->render('treasury/form', [
            'title' => __('treasury.copy_movement'),
            'isEdit' => false,
            'copy_source' => (int) $movement['id'],
            'action' => url('/treasury'),
            'return_url' => url($returnPath),
            'return_path' => $returnPath,
            'movement' => $copy,
            'default_category' => (string) ($movement['category'] ?? ''),
            'default_direction' => in_array($direction, ['income', 'expense', 'transfer'], true) ? $direction : 'income',
            'categories' => $categories,
            'category_groups' => $this->treasury->categoryGroups(),
            'accounts' => $this->treasury->accountOptions(),
            'account_balances' => $this->treasury->accountBalances(),
            'payment_method_groups' => $this->treasury->paymentMethodGroups(),
            'members' => $this->members->listForSelect(),
            'config' => $this->components->config('treasury', ['auto_from_payments' => true]),
            'beneficiaries' => $this->treasury->beneficiaries(),
            'currency' => $this->currency,
            'budget_items' => $this->budgets->itemOptionsForMovement(
                (int) date('Y'),
                (int) ($movement['budget_item_id'] ?? 0)
            ),
        ]);
    }

    public function delete(Request $request, string $id): void
    {
        require_component('treasury');
        $returnPath = $this->listReturnPath($request);
        $movement = $this->treasury->find((int) $id);
        if ($movement === null) {
            $this->flash('errors', ['treasury' => __('errors.404')]);
            redirect($returnPath);
        }
        $deleted = $this->treasury->delete((int) $id, $request->ip(), auth_user()['id'] ?? null);
        if ($deleted) {
            $this->flash('success', __('treasury.deleted'));
        } else {
            $this->flash('errors', ['treasury' => __('errors.404')]);
        }
        redirect($returnPath);
    }

    public function update(Request $request, string $id): void
    {
        require_component('treasury');
        // Ledger state to come back to (filters, sort, page) after the save or a validation error.
        $returnPath = $this->listReturnPath($request);
        $editPath = '/treasury/' . (int) $id . '/edit';
        // `embed=1`: the form runs inside an add-on iframe, so every redirect keeps the bare layout.
        $embed = trim((string) $request->input('embed', '')) === '1';
        $embedParams = $embed ? ['embed' => '1'] : [];
        if (upload_post_too_large()) {
            $this->flash('errors', [
                'invoice_pdf' => __('documents.upload_too_large', ['max' => upload_max_mb()]),
            ]);
            $this->rememberOld($request->all());
            redirect(self::withQuery($editPath, ['retry' => '1', 'ret' => $returnPath] + $embedParams));
        }
        $posted = $request->all();
        $before = $this->treasury->find((int) $id);
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
                redirect(self::withQuery($editPath, [
                    'retry' => '1',
                    'conflict' => '1',
                    'v' => (string) (int) ($result['current_version'] ?? 0),
                    'ret' => $returnPath,
                ] + $embedParams));
            }
            $this->flash('errors', $result['errors'] ?? []);
            redirect(self::withQuery($editPath, ['retry' => '1', 'ret' => $returnPath] + $embedParams));
        }
        $this->clearOld();
        $this->flash('success', __('treasury.updated'));
        // One-shot marker for the persistent status next to the save button (the layout flash
        // auto-dismisses and the operator is often scrolled to the bottom of the card).
        $this->flash('treasury_saved', (string) (int) $id);
        // Modification with classification: the tab with the proposal on similar movements reopens.
        $classificationChanged = trim((string) ($posted['category'] ?? '')) !== trim((string) ($before['category'] ?? ''))
            || strtoupper(trim((string) ($posted['runts_line'] ?? ''))) !== strtoupper(trim((string) ($before['runts_line'] ?? '')))
            || (int) ($posted['budget_item_id'] ?? 0) !== (int) ($before['budget_item_id'] ?? 0);
        $hasClassification = trim((string) ($posted['category'] ?? '')) !== ''
            || trim((string) ($posted['runts_line'] ?? '')) !== ''
            || (int) ($posted['budget_item_id'] ?? 0) > 0;
        if ($classificationChanged || $hasClassification) {
            $changedFields = [];
            if (trim((string) ($posted['category'] ?? '')) !== trim((string) ($before['category'] ?? ''))) {
                $changedFields[] = 'category';
            }
            if (strtoupper(trim((string) ($posted['runts_line'] ?? ''))) !== strtoupper(trim((string) ($before['runts_line'] ?? '')))) {
                $changedFields[] = 'runts_line';
            }
            if ((int) ($posted['budget_item_id'] ?? 0) !== (int) ($before['budget_item_id'] ?? 0)) {
                $changedFields[] = 'budget_item_id';
            }
            if ($changedFields === []) {
                foreach (['category', 'runts_line', 'budget_item_id'] as $probeField) {
                    $probeValue = (string) ($posted[$probeField] ?? '');
                    if ($probeField === 'budget_item_id' ? (int) $probeValue > 0 : trim($probeValue) !== '') {
                        $changedFields[] = $probeField;
                    }
                }
            }
            $_SESSION['_similar_probe'][(int) $id] = [
                'fields' => $changedFields,
                'originals' => [
                    'category' => (string) ($before['category'] ?? ''),
                    'runts_line' => (string) ($before['runts_line'] ?? ''),
                    'budget_item_id' => (string) (int) ($before['budget_item_id'] ?? 0),
                ],
            ];
            // Stay on the card: the “similar movements” proposal is shown right above the form.
            // The embedded quick edit must still mark the reload, otherwise the add-on dialog stays
            // open after a save that changed the classification.
            redirect(self::withQuery($editPath, array_merge([
                'ret' => $returnPath,
                'similar' => (string) (int) $id,
            ], $embedParams, $embed ? ['saved' => '1'] : [])));
        }
        // Stay on the card after saving: the operator keeps working on the same record. An embedded
        // quick edit marks the reload so the parent dialog can refresh the data it shows.
        redirect(self::withQuery($editPath, array_merge(
            ['ret' => $returnPath],
            $embedParams,
            $embed ? ['saved' => '1'] : []
        )));
    }

    public function store(Request $request): void
    {
        require_component('treasury');
        // Ledger state to come back to (filters, sort, page) after the save or a validation error.
        $returnPath = $this->listReturnPath($request);
        if (upload_post_too_large()) {
            $this->flash('errors', [
                'invoice_pdf' => __('documents.upload_too_large', ['max' => upload_max_mb()]),
            ]);
            $this->rememberOld($request->all());
            redirect(self::withQuery('/treasury/create', ['retry' => '1', 'ret' => $returnPath]));
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
            redirect(self::withQuery('/treasury/create', ['retry' => '1', 'ret' => $returnPath]));
        }
        // Recurring operation: the rule starts from the date of the just saved movement.
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
        // Classified movement: the form reopens with the proposal "also applies to similar".
        $classified = trim((string) $request->input('category', '')) !== ''
            || trim((string) $request->input('runts_line', '')) !== ''
            || (int) $request->input('budget_item_id', 0) > 0;
        $newId = (int) ($result['id'] ?? 0);
        if ($newId > 0) {
            // Back to the ledger pointing at the new row (copies included): the list scrolls to it
            // and blinks it. The page is dropped so the first batch really contains it.
            redirect(self::ledgerFocusPath(
                $returnPath,
                $newId,
                $classified ? ['similar' => (string) $newId] : []
            ));
        }
        redirect($returnPath);
    }

    /**
     * Moves similar to the current one: used by the “apply to others too” panel.
     */
    public function similar(Request $request): void
    {
        require_component('treasury');
        if (!can('treasury.manage')) {
            $this->jsonResponse(['ok' => false, 'error' => __('errors.403')], 403);
            return;
        }
        $data = $request->all();
        // Fields that the user actually changed (sent by the client): those not touched do not filter similar
        // and do not appear in the summary.
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
            'member_id' => (int) ($data['member_id'] ?? 0),
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

    /**
     * Typical values for the four classification fields, computed from similar movements.
     * Used by the per-field hints on the movement form (new and edit).
     */
    public function pattern(Request $request): void
    {
        require_component('treasury');
        if (!can('treasury.manage')) {
            $this->jsonResponse(['ok' => false, 'error' => __('errors.403')], 403);
            return;
        }
        $data = $request->all();
        $movement = [
            'id' => (int) ($data['movement_id'] ?? 0),
            'direction' => (string) ($data['direction'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'beneficiary' => (string) ($data['beneficiary'] ?? ''),
            'payment_method' => (string) ($data['payment_method'] ?? ''),
            'category' => (string) ($data['category'] ?? ''),
            'runts_line' => (string) ($data['runts_line'] ?? ''),
            'budget_item_id' => (int) ($data['budget_item_id'] ?? 0),
            'amount' => (float) ($data['amount'] ?? 0),
        ];
        if ($movement['category'] === '__new__') {
            $movement['category'] = '';
        }
        $profile = $this->treasury->classificationProfile(
            $movement,
            (int) $movement['id'],
            24
        );
        $this->jsonResponse(['ok' => true] + $profile);
    }

    /**
     * “The current value is correct”: silence one anomaly for this movement/field.
     */
    public function acknowledgePattern(Request $request): void
    {
        require_component('treasury');
        if (!can('treasury.manage')) {
            $this->jsonResponse(['ok' => false, 'error' => __('errors.403')], 403);
            return;
        }
        $ok = $this->treasury->acknowledgePattern(
            (int) $request->input('movement_id', 0),
            (string) $request->input('field', ''),
            (string) $request->input('current', ''),
            (string) $request->input('typical', ''),
            auth_user()['id'] ?? null,
            $request->ip()
        );
        $this->jsonResponse([
            'ok' => $ok,
            'message' => $ok
                ? (string) __('treasury.pattern_acknowledged')
                : (string) __('errors.403'),
        ], $ok ? 200 : 422);
    }

    /**
     * Apply type / item Model D / cost center to the similar movements chosen.
     */
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
     * Original values ​​of the record (sent by the form): they are used to understand which values
     * ​​are "automatic" and therefore still updatable.
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
     * Readable summary of the changes that will be applied.
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

    public function dismissHealthAlert(Request $request): void
    {
        require_component('treasury');
        if (!can('treasury.manage')) {
            http_response_code(403);
            $this->render('errors/403');
            return;
        }
        $issueKey = trim((string) $request->input('issue_key', ''));
        $fingerprint = trim((string) $request->input('fingerprint', ''));
        $ok = $this->treasury->dismissHealthAlert(
            $issueKey,
            $fingerprint,
            auth_user()['id'] ?? null,
            $request->ip()
        );
        if ($ok) {
            $this->flash('success', __('treasury.health_dismissed'));
        } else {
            $this->flash('errors', ['treasury' => __('treasury.health_dismiss_failed')]);
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
        $count = 0;
        $skipped = 0;
        if ($action === 'transfer') {
            $transfer = $this->treasury->transferSelected(array_values($ids), (string) $request->input('target_account', ''), $request->ip(), auth_user()['id'] ?? null);
            if (empty($transfer['ok'])) {
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => false, 'message' => (string) ($transfer['error'] ?? __('validation.required'))], JSON_UNESCAPED_UNICODE);
                    return;
                }
                $this->flash('errors', ['transfer' => (string) ($transfer['error'] ?? __('validation.required'))]);
                redirect('/treasury');
            }
            $count = (int) ($transfer['count'] ?? 0);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'action' => 'transfer', 'count' => $count, 'message' => (string) __('treasury.transfer_done', ['count' => (string) $count])], JSON_UNESCAPED_UNICODE);
                return;
            }
            $this->flash('success', __('treasury.transfer_done', ['count' => (string) $count]));
            redirect('/treasury');
        } elseif ($action === 'unapprove') {
            $count = $this->treasury->setApprovalMany(array_values($ids), false, $request->ip(), auth_user()['id'] ?? null);
            if ($count === 0) {
                // Only cash rows were selected: the cash desk never needs the first-note approval.
                $message = (string) __('treasury.bulk_unapproved_cash');
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => true, 'action' => 'unapprove', 'count' => 0, 'message' => $message], JSON_UNESCAPED_UNICODE);
                    return;
                }
                $this->flash('success', $message);
                redirect('/treasury');
            }
        } elseif ($action === 'approve') {
            $count = $this->treasury->approveMany(array_values($ids), $request->ip(), auth_user()['id'] ?? null);
        } elseif ($action === 'delete') {
            $count = $this->treasury->deleteMany(array_values($ids), $request->ip());
        } elseif ($action === 'update') {
            // Bulk classification: only the fields left on “keep” stay untouched, everything else
            // overwrites the current value (explicit confirmation in the UI).
            $result = $this->treasury->updateMany(array_values($ids), [
                'account' => (string) $request->input('account', ''),
                'category' => (string) $request->input('category', ''),
                'payment_method' => (string) $request->input('payment_method', ''),
                'runts_line' => (string) $request->input('runts_line', ''),
                'budget_item_id' => (string) $request->input('budget_item_id', ''),
                'description' => (string) $request->input('description', ''),
                'description_action' => (string) $request->input('description_action', ''),
                'notes' => (string) $request->input('notes', ''),
                'notes_action' => (string) $request->input('notes_action', ''),
                // Counterparty: a linked member (`__none__` unlinks) or a free-text supplier; the
                // two exclude each other, exactly like in the movement form.
                'member_id' => (string) $request->input('member_id', ''),
                'beneficiary' => (string) $request->input('beneficiary', ''),
                'beneficiary_action' => (string) $request->input('beneficiary_action', ''),
            ], $request->ip());
            $count = (int) ($result['count'] ?? 0);
            $skipped = (int) ($result['skipped'] ?? 0);
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
                'skipped' => $skipped,
                'ids' => array_values($ids),
                'message' => $action === 'update' && $count > 0
                    ? ($skipped > 0
                        ? __('treasury.bulk_edit_done_skipped', ['count' => (string) $count, 'skipped' => (string) $skipped])
                        : __('treasury.bulk_edit_done', ['count' => (string) $count]))
                    : null,
                'totals' => $action === 'approve' ? $this->totalsPayload() : null,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($action === 'unapprove') {
            $this->flash('success', __('treasury.bulk_unapproved', ['count' => (string) $count]));
        } elseif ($action === 'approve') {
            $this->flash('success', __('treasury.bulk_approved', ['count' => (string) $count]));
        } elseif ($action === 'update') {
            $this->flash('success', $skipped > 0
                ? __('treasury.bulk_edit_done_skipped', ['count' => (string) $count, 'skipped' => (string) $skipped])
                : __('treasury.bulk_edit_done', ['count' => (string) $count]));
        } else {
            $this->flash('success', __('treasury.bulk_deleted', ['count' => (string) $count]));
        }
        redirect('/treasury');
    }

    /**
     * Updated totals (balance, income, expenses, to be approved) for updating in place after an
     * approval: the page does not need to reload to show them.
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
     * Live wallet balances for the movement form (the "From account" select).
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
     * Serves the bank-statement excerpt linked to a cash-book movement. The crop is stored in the
     * central document repository, but the document archive can be closed to the role that manages
     * the cash book (e.g. the treasurer): this route keeps the evidence readable with treasury.view
     * and exposes only the excerpts the add-on linked to a movement.
     */
    public function statementExcerpt(Request $request, string $id): void
    {
        require_component('treasury');
        $documentId = (int) $id;
        $row = $documentId > 0 ? $this->files->fileInfo($documentId) : null;
        $path = $documentId > 0 ? $this->files->absolutePath($documentId) : null;
        if ($row === null || $path === null || (string) ($row['source_section'] ?? '') !== self::STATEMENT_SOURCE_SECTION) {
            http_response_code(404);
            $this->render('errors/404');
            return;
        }
        $linkedToMovement = false;
        foreach ($this->files->linksFor($documentId) as $link) {
            if ((string) ($link['section'] ?? '') === 'treasury'
                && preg_match('/^movement:\d+$/', (string) ($link['context_ref'] ?? '')) === 1) {
                $linkedToMovement = true;
                break;
            }
        }
        if (!$linkedToMovement) {
            http_response_code(404);
            $this->render('errors/404');
            return;
        }
        $this->files->registerDownload($documentId);
        $detected = mime_content_type($path);
        $mime = is_string($detected) && $detected !== '' ? $detected : 'image/png';
        $name = trim((string) ($row['original_name'] ?? '')) ?: basename($path);
        $name = str_replace(['"', "\r", "\n"], '', $name);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) (filesize($path) ?: 0));
        readfile($path);
    }

    /**
     * Ledger filters from the query string (validated; period presets expand to a date range).
     *
     * @return array{direction:string,category:string,runts_line:string,budget_item_id:string,account:string,from:string,to:string,period:string,month:string,year:string,approval:string,anomaly:string,pattern_ids?:list<int>}
     */
    /**
     * Grouped Model D entries (Expenses / Revenues) for advanced filtering.
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

    /** How many “advanced” filters are active (shown on the button). */
    private function advancedFilterCount(array $filters): int
    {
        $count = 0;
        foreach (['category', 'runts_line', 'budget_item_id', 'account', 'approval', 'anomaly'] as $key) {
            if (trim((string) ($filters[$key] ?? '')) !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * “Gestione controparti”: every counterparty of the register with its income/expense shares,
     * a member badge (and member filter) and a link to the ledger filtered on that counterparty.
     */
    public function suppliers(Request $request): void
    {
        require_component('treasury');
        $query = trim((string) $request->input('q', ''));
        if (mb_strlen($query) > 120) {
            $query = mb_substr($query, 0, 120);
        }
        $sort = (string) $request->input('sort', 'volume');
        if (!in_array($sort, ['volume', 'name'], true)) {
            $sort = 'volume';
        }
        // Members in / members out: '' = all, 'members' = only members, 'others' = exclude them.
        $party = (string) $request->input('party', '');
        if (!in_array($party, ['members', 'others'], true)) {
            $party = '';
        }
        $overview = $this->treasury->supplierOverview($query, $sort, $party);
        $this->render('treasury/suppliers', [
            'title' => __('treasury.suppliers_title'),
            'suppliers' => $overview['rows'],
            'supplier_totals' => $overview['totals'],
            'search_query' => $query,
            'sort' => $sort,
            'party' => $party,
            'currency' => $this->currency,
        ]);
    }

    /**
     * Movements of one counterparty, shown in the dialog opened from “Gestione controparti”.
     * Read-only and compact: the operator keeps the counterparty list behind the popup, while the
     * full ledger (filters, grouping, bulk actions) stays one click away.
     */
    public function supplierMovements(Request $request): void
    {
        require_component('treasury');
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $request->input('name', '')));
        if (mb_strlen($name) > 190) {
            $name = mb_substr($name, 0, 190);
        }
        if ($name === '') {
            http_response_code(404);
            $this->render('errors/404');
            return;
        }
        // Same filter the ledger drill-down uses, so the popup and “Apri in Prima cassa” agree.
        $filters = $this->ledgerFilters(new Request('GET', '/treasury', ['beneficiary' => $name], [], [], []));
        $limit = 100;
        $total = $this->treasury->ledgerTotal('', $filters);
        $ledger = $this->treasury->ledger($limit, '', 'date_desc', $filters, 0);
        $this->render('treasury/supplier_movements', [
            'title' => __('treasury.supplier_movements_title', ['name' => $name]),
            'beneficiary' => $name,
            'movements' => is_array($ledger['movements'] ?? null) ? $ledger['movements'] : [],
            'total' => $total,
            'currency' => $this->currency,
            'ledger_url' => url('/treasury?' . http_build_query(['beneficiary' => $name])),
        ], 'layouts/embed');
    }

    public function loans(Request $request): void
    {
        $this->renderPositions($request, 'loan');
    }

    public function deposits(Request $request): void
    {
        $this->renderPositions($request, 'deposit');
    }

    /**
     * Loan and security-deposit registers share one view: same shape as the supplier page, with
     * the two flows of the category and the amount still open for each counterparty.
     */
    private function renderPositions(Request $request, string $kind): void
    {
        require_component('treasury');
        $query = trim((string) $request->input('q', ''));
        if (mb_strlen($query) > 120) {
            $query = mb_substr($query, 0, 120);
        }
        $sort = (string) $request->input('sort', 'volume');
        if (!in_array($sort, ['volume', 'name'], true)) {
            $sort = 'volume';
        }
        $isLoan = $kind === 'loan';
        $overview = $isLoan
            ? $this->treasury->loanOverview($query, $sort)
            : $this->treasury->depositOverview($query, $sort);
        $this->render('treasury/positions', [
            'title' => $isLoan ? __('treasury.loans_title') : __('treasury.deposits_title'),
            'kind' => $isLoan ? 'loan' : 'deposit',
            'category' => $isLoan ? 'member_loan' : 'security_deposit',
            'positions' => $overview['rows'],
            'position_totals' => $overview['totals'],
            'search_query' => $query,
            'sort' => $sort,
            'currency' => $this->currency,
        ]);
    }


    /**
     * Proposal “also apply to similar movements” calculated server-side for a movement.
     *
     * @return array<string,mixed>|null
     */
    private function similarProbe(int $movementId): ?array
    {
        if ($movementId < 1 || !can('treasury.manage')) {
            return null;
        }
        $movement = $this->treasury->find($movementId);
        if ($movement === null) {
            return null;
        }
        $values = [
            'category' => (string) ($movement['category'] ?? ''),
            'runts_line' => (string) ($movement['runts_line'] ?? ''),
            'budget_item_id' => (string) (int) ($movement['budget_item_id'] ?? 0),
        ];
        $context = $_SESSION['_similar_probe'][$movementId] ?? null;
        unset($_SESSION['_similar_probe'][$movementId]);
        if (is_array($context)) {
            $fields = array_values(array_intersect((array) ($context['fields'] ?? []), ['category', 'runts_line', 'budget_item_id']));
            $originals = (array) ($context['originals'] ?? $values);
        } else {
            $fields = [];
            foreach ($values as $field => $value) {
                if ($field === 'budget_item_id' ? (int) $value > 0 : trim($value) !== '') {
                    $fields[] = $field;
                }
            }
            $originals = $values;
        }
        if ($fields === []) {
            return null;
        }
        $result = $this->treasury->similarMovements([
            'direction' => (string) ($movement['direction'] ?? ''),
            'description' => (string) ($movement['description'] ?? ''),
            'beneficiary' => (string) ($movement['beneficiary'] ?? ''),
            'member_id' => (int) ($movement['member_id'] ?? 0),
        ], TreasuryService::SIMILAR_LIST_LIMIT, $movementId, $fields, $originals);
        if (($result['total'] ?? 0) < 1) {
            return null;
        }

        return [
            'movement' => $movement,
            'ids' => $result['ids'],
            'matches' => $result['matches'],
            'total' => (int) $result['total'],
            'skipped' => (int) ($result['skipped'] ?? 0),
            'fields' => $result['fields'] ?? $fields,
            'amount_total_formatted' => (string) ($result['amount_total_formatted'] ?? ''),
            'date_from_formatted' => (string) ($result['date_from_formatted'] ?? ''),
            'date_to_formatted' => (string) ($result['date_to_formatted'] ?? ''),
            'originals' => $originals,
            'changes' => $this->describeClassification([
                'category' => $values['category'],
                'runts_line' => $values['runts_line'],
                'budget_item_id' => $values['budget_item_id'],
            ], $fields),
        ];
    }

    /**
     * Apply the proposed classification (classic form, without JS).
     */
    public function applySimilarForm(Request $request): void
    {
        require_component('treasury');
        if (!can('treasury.manage')) {
            http_response_code(403);
            $this->render('errors/403');
            return;
        }
        $ids = [];
        foreach ((array) $request->input('ids', []) as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $movementId = (int) $request->input('movement_id', 0);
        $originals = [];
        $movement = $movementId > 0 ? $this->treasury->find($movementId) : null;
        if ($movement !== null) {
            $originals = [
                'category' => (string) ($movement['category'] ?? ''),
                'runts_line' => (string) ($movement['runts_line'] ?? ''),
                'budget_item_id' => (string) (int) ($movement['budget_item_id'] ?? 0),
            ];
        }
        $updated = $this->treasury->applyClassification(array_values($ids), [
            'category' => (string) $request->input('category', ''),
            'runts_line' => (string) $request->input('runts_line', ''),
            'budget_item_id' => (string) $request->input('budget_item_id', ''),
        ], $request->ip(), $originals);
        $this->flash('success', __('treasury.similar_applied', ['count' => (string) $updated]));
        // Applying the proposal keeps the operator where they are: on the card that started it,
        // otherwise on the ledger.
        $backPath = $this->recordReturnPath($request);
        if ($backPath !== '') {
            redirect(self::withQuery($backPath, ['ret' => $this->listReturnPath($request)]));
        }
        redirect($this->listReturnPath($request));
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
        $anomaly = (string) $request->input('anomaly', '') === '1' ? '1' : '';
        // Supplier drill-down from “Gestione Controparti”: exact name, case/space insensitive.
        $beneficiary = trim((string) preg_replace('/\s+/u', ' ', (string) $request->input('beneficiary', '')));
        if (mb_strlen($beneficiary) > 190) {
            $beneficiary = mb_substr($beneficiary, 0, 190);
        }
        $from = $this->isoDate((string) $request->input('from', ''));
        $to = $this->isoDate((string) $request->input('to', ''));
        $month = (int) $request->input('month', 0);
        if ($month < 1 || $month > 12) {
            $month = 0;
        }
        $year = (int) $request->input('year', 0);
        if ($year < 1900 || $year > 2200) {
            $year = 0;
        }
        // An explicit month/year replaces the relative presets (“this month”, “last 30 days”…).
        if ($month > 0 || $year > 0) {
            $period = '';
        }
        if ($from === '' && $to === '' && $period !== '') {
            [$from, $to] = match ($period) {
                'month' => [date('Y-m-01'), date('Y-m-t')],
                'year' => [date('Y-01-01'), date('Y-12-31')],
                default => [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
            };
        }
        // Explicit id list (Apri gruppo for identical duplicates).
        $idsRaw = trim((string) $request->input('ids', ''));
        $ids = [];
        if ($idsRaw !== '') {
            foreach (explode(',', $idsRaw) as $piece) {
                $id = (int) trim($piece);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
                if (count($ids) >= 200) {
                    break;
                }
            }
        }
        $filters = [
            'direction' => $direction,
            'category' => $category,
            'runts_line' => $runtsLine,
            'budget_item_id' => $budgetItem,
            'account' => $account,
            'from' => $from,
            'to' => $to,
            'period' => $period,
            'month' => $month > 0 ? (string) $month : '',
            'year' => $year > 0 ? (string) $year : '',
            'approval' => $approval,
            'anomaly' => $anomaly,
            'beneficiary' => $beneficiary,
            'no_counterparty' => (string) $request->input('no_counterparty', '') === '1' ? '1' : '',
            'ids' => $ids !== [] ? implode(',', array_values($ids)) : '',
        ];
        if ($anomaly === '1') {
            $filters['pattern_ids'] = $this->treasury->ledgerAnomalyIds();
        } elseif ($ids !== []) {
            $filters['pattern_ids'] = array_values($ids);
        }

        return $filters;
    }

    private function isoDate(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /**
     * Ledger state the operator came from ("/treasury?q=…&sort=…&page=2"), so “Back”, a retry after
     * a validation error and the redirect after a save land on the same filters, sort and page.
     *
     * Only a *relative* `/treasury` path carrying the ledger keys is accepted: an external host,
     * another screen, a fragment or an unknown parameter are dropped (no open redirect).
     */
    /**
     * Ledger state carried by the `ret` parameter, cleaned (only a relative `/treasury` path with the
     * ledger keys survives; no external host, scheme, fragment, backslash or unknown parameter).
     *
     * @return array<string,string>
     */
    private function listReturnQuery(Request $request): array
    {
        $raw = trim((string) $request->input('ret', ''));
        if ($raw === '' || mb_strlen($raw) > 2000 || preg_match('/[\x00-\x1f\x7f\\\\]/', $raw) === 1) {
            return [];
        }
        $parts = parse_url($raw);
        if (!is_array($parts)
            || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            return [];
        }
        if (rtrim((string) ($parts['path'] ?? ''), '/') !== '/treasury') {
            return [];
        }
        $query = is_string($parts['query'] ?? null) ? $parts['query'] : '';
        $parsed = [];
        if ($query !== '') {
            parse_str($query, $parsed);
        }
        $clean = [];
        foreach (['q', 'period', 'direction', 'category', 'account', 'approval', 'anomaly', 'month', 'year', 'sort', 'group', 'page', 'runts_line', 'budget_item_id', 'beneficiary', 'no_counterparty', 'ids'] as $key) {
            $value = $parsed[$key] ?? null;
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '' || mb_strlen($value) > 120) {
                continue;
            }
            $clean[$key] = $value;
        }
        if (isset($clean['sort']) && !in_array($clean['sort'], TreasuryService::SORTS, true)) {
            unset($clean['sort']);
        }
        if (isset($clean['group']) && $clean['group'] !== '1') {
            unset($clean['group']);
        }
        if (isset($clean['page'])) {
            $page = (int) $clean['page'];
            if ($page < 2) {
                unset($clean['page']);
            } else {
                $clean['page'] = (string) $page;
            }
        }

        return $clean;
    }

    private function listReturnPath(Request $request): string
    {
        // A movement corrected from the member card comes back to that card, not to the ledger.
        $raw = trim((string) $request->input('ret', ''));
        if (preg_match('#^/members/[0-9]+$#', $raw) === 1) {
            return $raw;
        }
        $clean = $this->listReturnQuery($request);

        return '/treasury' . ($clean === [] ? '' : '?' . http_build_query($clean));
    }

    /**
     * Card the panel came from (“/treasury/12/edit”), used by the “apply to similar movements”
     * action so the operator stays on the record. Only that exact shape is accepted: no host, no
     * query (the ledger state is re-attached by the caller), no other screen.
     */
    private function recordReturnPath(Request $request): string
    {
        $raw = trim((string) $request->input('back', ''));
        if ($raw === '' || mb_strlen($raw) > 200) {
            return '';
        }
        $parts = parse_url($raw);
        if (!is_array($parts)
            || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || isset($parts['query'])) {
            return '';
        }
        $path = (string) ($parts['path'] ?? '');

        return preg_match('#^/treasury/[0-9]+/edit$#', $path) === 1 ? $path : '';
    }

    /**
     * Append parameters to a relative path, keeping the existing query string.
     *
     * @param array<string,string> $params
     */
    private static function withQuery(string $path, array $params): string
    {
        $params = array_filter($params, static fn (string $value): bool => $value !== '');
        if ($params === []) {
            return $path;
        }

        return $path . (str_contains($path, '?') ? '&' : '?') . http_build_query($params);
    }

    /**
     * Ledger URL after a save, pointing at the row that was just created: the current filters and
     * sort are kept, the page is dropped (the new movement sits in the first batch) and
     * `highlight=<id>` drives the scroll + blink in the ledger script. Other return paths (e.g. the
     * member card) are left untouched: there is no ledger row to point at.
     *
     * @param array<string,string> $extra
     */
    private static function ledgerFocusPath(string $returnPath, int $movementId, array $extra = []): string
    {
        if ($movementId < 1 || !str_starts_with($returnPath, '/treasury')) {
            return $returnPath;
        }
        $path = $returnPath;
        $query = [];
        $mark = strpos($path, '?');
        if ($mark !== false) {
            parse_str(substr($path, $mark + 1), $query);
            $path = substr($path, 0, $mark);
        }
        unset($query['page']);
        $query = array_merge($query, $extra, ['highlight' => (string) $movementId]);

        return $path . '?' . http_build_query($query);
    }
}
