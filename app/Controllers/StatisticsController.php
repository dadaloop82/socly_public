<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\ComponentService;
use Socly\Services\CurrencyService;
use Socly\Services\StatisticsService;
use Socly\Support\Permission;

/** Read-only statistics page (members, cards, registrations, money). */
final class StatisticsController extends BaseController
{
    public function __construct(
        View $view,
        private readonly StatisticsService $statistics,
        private readonly ComponentService $components,
        private readonly CurrencyService $currency
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        if (!can(Permission::MEMBERS_VIEW) || !$this->components->isEnabled('members')) {
            http_response_code(403);
            $this->render('errors/403');
            return;
        }

        $overview = $this->statistics->overview();
        $demographics = $this->statistics->demographics();
        $registrations = $this->statistics->registrations();
        $cards = $this->statistics->cards();
        $finances = $this->statistics->finances();

        // Generic chart payload: the view renders one canvas per key and app.js feeds Chart.js.
        $charts = [
            'gender' => [
                'type' => 'doughnut',
                'labels' => $demographics['gender']['labels'],
                'values' => $demographics['gender']['values'],
            ],
            'age_bands' => [
                'type' => 'bar',
                'labels' => $demographics['age_bands']['labels'],
                'values' => $demographics['age_bands']['values'],
            ],
            'email_providers' => [
                'type' => 'doughnut',
                'labels' => $demographics['email_providers']['labels'],
                'values' => $demographics['email_providers']['values'],
            ],
            'cities' => [
                'type' => 'bar',
                'horizontal' => true,
                'labels' => $demographics['cities']['labels'],
                'values' => $demographics['cities']['values'],
            ],
            'languages' => [
                'type' => 'doughnut',
                'labels' => $demographics['languages']['labels'],
                'values' => $demographics['languages']['values'],
            ],
            'registrations_monthly' => [
                'type' => 'bar',
                'labels' => $registrations['monthly']['labels'],
                'values' => $registrations['monthly']['values'],
            ],
            'registrations_weekday' => [
                'type' => 'bar',
                'labels' => $registrations['weekdays']['labels'],
                'values' => $registrations['weekdays']['values'],
            ],
            'seasons' => [
                'type' => 'doughnut',
                'labels' => $registrations['seasons']['labels'],
                'values' => $registrations['seasons']['values'],
            ],
            'cards_status' => [
                'type' => 'doughnut',
                'labels' => $cards['status']['labels'],
                'values' => $cards['status']['values'],
            ],
            'cards_type' => [
                'type' => 'doughnut',
                'labels' => $cards['by_type']['labels'],
                'values' => $cards['by_type']['values'],
            ],
            'cards_year' => [
                'type' => 'bar',
                'labels' => $cards['by_year']['labels'],
                'values' => $cards['by_year']['values'],
            ],
            'finance_monthly' => [
                'type' => 'bar',
                'money' => true,
                'labels' => $finances['monthly']['labels'],
                'values' => $finances['monthly']['values'],
            ],
            'finance_method' => [
                'type' => 'doughnut',
                'money' => true,
                'labels' => $finances['by_method']['labels'],
                'values' => $finances['by_method']['values'],
            ],
            'finance_type' => [
                'type' => 'doughnut',
                'money' => true,
                'labels' => $finances['by_type']['labels'],
                'values' => $finances['by_type']['values'],
            ],
        ];

        $this->render('statistics/index', [
            'title' => __('statistics.title'),
            'overview' => $overview,
            'demographics' => $demographics,
            'registrations' => $registrations,
            'cards' => $cards,
            'finances' => $finances,
            'currency' => $this->currency,
            'chartsJson' => json_encode(
                ['charts' => $charts],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);
    }
}
