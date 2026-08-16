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
        $ledger = $this->treasury->ledger(200, $query);
        $config = $this->components->config('treasury', ['auto_from_payments' => true]);
        $this->render('treasury/index', [
            'title' => __('treasury.title'),
            'ledger' => $ledger,
            'movement_groups' => $this->treasury->groupedByCategory(200, $query),
            'config' => $config,
            'members' => $this->members->listForSelect(),
            'categories' => $this->treasury->categoryOptions(),
            'default_category' => $this->treasury->defaultCategory(),
            'search_query' => $query,
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
        $this->render('treasury/edit', [
            'title' => __('treasury.edit'),
            'movement' => $movement,
            'categories' => $categories,
            'members' => $this->members->listForSelect(),
            'config' => $this->components->config('treasury', ['auto_from_payments' => true]),
            'beneficiaries' => $this->treasury->beneficiaries(),
            'currency' => $this->currency,
        ]);
    }

    public function update(Request $request, string $id): void
    {
        require_component('treasury');
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
        $result = $this->treasury->create(
            $request->all(),
            $request->file('invoice_pdf'),
            $request->ip(),
            auth_user()['id'] ?? null
        );
        if (empty($result['ok'])) {
            $this->flash('errors', $result['errors'] ?? ['treasury' => __('validation.required')]);
            $this->rememberOld($request->all());
            redirect('/treasury');
        }
        $this->flash('success', __('treasury.saved'));
        redirect('/treasury');
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
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($path) . '"');
        readfile($path);
    }
}
