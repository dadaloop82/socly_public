<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\DeadlineService;
use Socly\Services\MemberService;

final class DeadlinesController extends BaseController
{
    public function __construct(
        View $view,
        private readonly DeadlineService $deadlines,
        private readonly MemberService $members
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        require_component('deadlines');
        $query = trim((string) $request->input('q', ''));
        if (mb_strlen($query) > 120) {
            $query = mb_substr($query, 0, 120);
        }
        $bucket = trim((string) $request->input('filter', ''));
        if (!in_array($bucket, ['overdue', 'soon', 'open', 'done', 'all', ''], true)) {
            $bucket = '';
        }
        $category = trim((string) $request->input('category', ''));
        if ($category !== '' && !in_array($category, array_column($this->deadlines->categoryOptions(), 'key'), true)) {
            $category = '';
        }
        $today = date('Y-m-d');
        $soon = date('Y-m-d', strtotime('+30 days'));
        $openOnly = !in_array($bucket, ['done', 'all'], true);
        $this->render('deadlines/index', [
            'title' => __('deadlines.title'),
            'deadline_items' => $this->deadlines->upcoming(200, $query, $openOnly, $bucket, null, $category),
            'counts' => $this->deadlines->counts(),
            'today' => $today,
            'soon' => $soon,
            'members' => $this->members->listForSelect(),
            'categories' => $this->deadlines->categoryOptions(),
            'category_groups' => $this->deadlines->categoryGroupedOptions(),
            'default_category' => $this->deadlines->defaultCategory(),
            'search_query' => $query,
            'active_filter' => $bucket,
            'filter_category' => $category,
        ]);
    }

    public function create(Request $request): void
    {
        require_component('deadlines');
        $this->render('deadlines/form', [
            'title' => __('deadlines.add'),
            'isEdit' => false,
            'action' => url('/deadlines'),
            'deadline' => null,
            'default_category' => $this->deadlines->defaultCategory(),
            'categories' => $this->deadlines->categoryOptions(),
            'category_groups' => $this->deadlines->categoryGroupedOptions(),
            'members' => $this->members->listForSelect(),
        ]);
    }

    public function edit(Request $request, string $id): void
    {
        require_component('deadlines');
        $item = $this->deadlines->find((int) $id);
        if ($item === null) {
            http_response_code(404);
            $this->flash('errors', ['deadline' => __('errors.404')]);
            redirect('/deadlines');
        }
        if ($this->deadlines->isSystem($item)) {
            $this->flash('errors', ['deadline' => __('deadlines.system_readonly')]);
            redirect('/deadlines');
        }
        $categories = $this->deadlines->categoryOptions();
        $catKey = trim((string) ($item['category'] ?? ''));
        if ($catKey !== '' && !in_array($catKey, array_column($categories, 'key'), true)) {
            $categories[] = [
                'key' => $catKey,
                'label' => $this->deadlines->categoryLabel($catKey),
                'builtin' => false,
            ];
        }
        $this->render('deadlines/form', [
            'title' => __('deadlines.edit'),
            'isEdit' => true,
            'action' => url('/deadlines/' . (int) $item['id']),
            'deadline' => $item,
            'default_category' => (string) ($item['category'] ?? 'general'),
            'categories' => $categories,
            'category_groups' => $this->deadlines->categoryGroupedOptions(),
            'members' => $this->members->listForSelect(),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        require_component('deadlines');
        $result = $this->deadlines->update((int) $id, $request->all(), $request->ip());
        if (empty($result['ok'])) {
            $this->flash('errors', $result['errors'] ?? []);
            $this->rememberOld($request->all());
            redirect('/deadlines/' . (int) $id . '/edit');
        }
        $this->flash('success', __('deadlines.updated'));
        redirect('/deadlines');
    }

    public function store(Request $request): void
    {
        require_component('deadlines');
        $result = $this->deadlines->create($request->all(), $request->ip());
        if (empty($result['ok'])) {
            $this->flash('errors', $result['errors'] ?? []);
            $this->rememberOld($request->all());
            redirect('/deadlines/create');
        }
        $this->flash('success', __('deadlines.saved'));
        redirect('/deadlines');
    }

    public function done(Request $request, string $id): void
    {
        require_component('deadlines');
        $this->deadlines->markDone((int) $id, $request->ip());
        $this->flash('success', __('deadlines.done'));
        redirect('/deadlines');
    }

    public function renew(Request $request, string $id): void
    {
        require_component('deadlines');
        $result = $this->deadlines->renewPlusYear((int) $id, $request->ip());
        if (empty($result['ok'])) {
            $this->flash('error', __('deadlines.system_readonly'));
            redirect('/deadlines');
        }
        $this->flash('success', __('deadlines.renewed'));
        redirect('/deadlines');
    }
}
