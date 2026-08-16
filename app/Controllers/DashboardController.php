<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\AssociationPeopleService;
use Socly\Services\ComponentService;
use Socly\Services\CurrencyService;
use Socly\Services\DeadlineService;
use Socly\Services\DocumentService;
use Socly\Services\MemberService;
use Socly\Services\TreasuryService;
use Socly\Support\Permission;

final class DashboardController extends BaseController
{
    public function __construct(
        View $view,
        private readonly MemberService $members,
        private readonly ComponentService $components,
        private readonly TreasuryService $treasury,
        private readonly DeadlineService $deadlines,
        private readonly DocumentService $documents,
        private readonly AssociationPeopleService $people,
        private readonly CurrencyService $currency
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        $showMembers = $this->components->isEnabled('members') && can(Permission::MEMBERS_VIEW);
        $showTreasury = $this->components->isEnabled('treasury') && can(Permission::TREASURY_VIEW);
        $showDeadlines = $this->components->isEnabled('deadlines') && can(Permission::DEADLINES_VIEW);
        $showDocuments = $this->components->isEnabled('documents') && can(Permission::DOCUMENTS_VIEW);
        $showOrg = $this->components->isEnabled('org_roles') && can(Permission::ORG_VIEW);

        $stats = $showMembers
            ? $this->members->dashboardStats()
            : [
                'members_total' => 0,
                'members_active' => 0,
                'members_expired' => 0,
                'members_suspended' => 0,
                'overdue_count' => 0,
                'collected_year' => 0.0,
                'collected_month' => 0.0,
                'new_members_year' => 0,
                'members_settled' => 0,
                'charts' => [
                    'collections' => ['labels' => [], 'values' => []],
                    'new_members' => ['labels' => [], 'values' => []],
                    'by_type' => ['labels' => [], 'values' => []],
                    'standing' => ['settled' => 0, 'overdue' => 0],
                ],
            ];

        $widgets = [
            'treasury' => null,
            'deadlines' => null,
            'documents' => null,
            'org' => null,
        ];

        if ($showTreasury) {
            $ledger = $this->treasury->ledger(5);
            $widgets['treasury'] = [
                'balance' => $ledger['balance'],
                'income' => $ledger['income'],
                'expense' => $ledger['expense'],
                'recent' => $ledger['movements'],
                'charts' => $this->treasury->dashboardCharts(),
            ];
        }

        if ($showDeadlines) {
            $widgets['deadlines'] = [
                'items' => $this->deadlines->upcoming(5),
                'counts' => $this->deadlines->counts(),
            ];
        }

        if ($showDocuments) {
            $recentDocs = $this->documents->all(5);
            $widgets['documents'] = [
                'recent' => $recentDocs,
                'total' => $this->documents->countAll(),
            ];
        }

        if ($showOrg) {
            $byRole = [];
            $peopleCount = 0;
            $vacant = 0;
            foreach ($this->people->roles() as $role) {
                $key = (string) ($role['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $list = $this->people->listByRole($key);
                $byRole[$key] = $list;
                $peopleCount += count($list);
                if ($list === []) {
                    $vacant++;
                }
            }
            $president = $byRole['president'][0] ?? null;
            $widgets['org'] = [
                'president' => $president,
                'people_count' => $peopleCount,
                'vacant_roles' => $vacant,
                'roles_count' => count($byRole),
            ];
        }

        $this->render('dashboard/index', [
            'title' => __('dashboard.title'),
            'stats' => $stats,
            'widgets' => $widgets,
            'enabled' => [
                'members' => $showMembers,
                'treasury' => $showTreasury,
                'deadlines' => $showDeadlines,
                'documents' => $showDocuments,
                'org' => $showOrg,
            ],
            'currency' => $this->currency,
            'chartsJson' => json_encode($stats['charts'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'chartI18n' => json_encode([
                'collected' => __('dashboard.chart_collected_series'),
                'newMembers' => __('dashboard.chart_new_members_series'),
                'currency' => $this->currency->display(),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'treasuryChartsJson' => json_encode(
                $widgets['treasury']['charts'] ?? [
                    'flow' => ['labels' => [], 'values' => []],
                    'expense_by_category' => ['labels' => [], 'values' => []],
                    'income_by_category' => ['labels' => [], 'values' => []],
                ],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            'treasuryChartI18n' => json_encode([
                'currency' => $this->currency->display(),
                'income' => __('treasury.direction_income'),
                'expense' => __('treasury.direction_expense'),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }
}
