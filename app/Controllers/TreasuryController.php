<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\ComponentService;
use Socly\Services\CurrencyService;
use Socly\Services\MemberService;
use Socly\Services\TreasuryService;

final class TreasuryController extends BaseController
{
    public function __construct(
        View $view,
        private readonly TreasuryService $treasury,
        private readonly MemberService $members,
        private readonly ComponentService $components,
        private readonly CurrencyService $currency
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        require_component('treasury');
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
        $ledger = $this->treasury->ledger(200, $query, $sort, $filters);
        $config = $this->components->config('treasury', ['auto_from_payments' => true]);
        $this->render('treasury/index', [
            'title' => __('treasury.title'),
            'ledger' => $ledger,
            'movement_groups' => $groupByCategory
                ? $this->treasury->groupedByCategory(200, $query, $sort, $filters)
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
            'accounts' => $this->treasury->accountOptions(),
            'account_balances' => $this->treasury->accountBalances(),
            'payment_method_groups' => $this->treasury->paymentMethodGroups(),
            'default_category' => $this->treasury->defaultCategory(),
            'search_query' => $query,
            'beneficiaries' => $this->treasury->beneficiaries(),
            'currency' => $this->currency,
            'hasAnyMovements' => $this->treasury->movementCount() > 0,
        ]);
    }

    public function create(Request $request): void
    {
        require_component('treasury');
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
        ]);
    }

    public function edit(Request $request, string $id): void
    {
        require_component('treasury');
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
        $this->render('treasury/form', [
            'title' => __('treasury.edit'),
            'isEdit' => true,
            'action' => url('/treasury/' . (int) $movement['id']),
            'movement' => $movement,
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
            redirect('/treasury/' . (int) $id . '/edit');
        }
        $result = $this->treasury->update(
            (int) $id,
            $request->all(),
            $request->file('invoice_pdf'),
            $request->ip(),
            auth_user()['id'] ?? null
        );
        if (empty($result['ok'])) {
            $this->flash('errors', $result['errors'] ?? []);
            $this->rememberOld($request->all());
            redirect('/treasury/' . (int) $id . '/edit');
        }
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
            redirect('/treasury/create');
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
            redirect('/treasury/create');
        }
        $this->flash('success', __('treasury.saved'));
        redirect('/treasury');
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
        if ($category !== '' && !in_array($category, array_column($this->treasury->categoryOptions(), 'key'), true)) {
            $category = '';
        }
        $period = (string) $request->input('period', '');
        if (!in_array($period, ['month', 'year', 'days30'], true)) {
            $period = '';
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
            'account' => $account,
            'from' => $from,
            'to' => $to,
            'period' => $period,
        ];
    }

    private function isoDate(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }
}
