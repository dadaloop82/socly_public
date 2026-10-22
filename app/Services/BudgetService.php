<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;

/**
 * Bilancio preventivo per anno sociale.
 *
 * Il consuntivo non viene duplicato in una tabella: è la somma dei movimenti di
 * prima cassa imputati alle voci (`treasury_movements.budget_item_id`), così il
 * confronto preventivo/consuntivo è sempre allineato al libro mastro.
 */
final class BudgetService
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';

    /** @var list<string> */
    public const TEMPLATES = ['empty', 'previous_budget', 'previous_actual', 'public_grant_model'];

    /** @var list<string> */
    private const DIRECTIONS = ['income', 'expense'];

    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
        private readonly TreasuryService $treasury
    ) {
    }

    /**
     * Budgets with planned totals, newest year first.
     *
     * @return list<array<string,mixed>>
     */
    public function listDocuments(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT b.*,
                    u.name AS approver_name,
                    COALESCE((SELECT SUM(i.amount) FROM budget_items i
                              WHERE i.budget_id = b.id AND i.direction = 'expense'), 0) AS planned_expense,
                    COALESCE((SELECT SUM(i.amount) FROM budget_items i
                              WHERE i.budget_id = b.id AND i.direction = 'income'), 0) AS planned_income
             FROM budgets b
             LEFT JOIN users u ON u.id = b.approved_by
             ORDER BY b.fiscal_year DESC, b.id DESC"
        );
        foreach ($rows as &$row) {
            $row['planned_expense'] = (float) ($row['planned_expense'] ?? 0);
            $row['planned_income'] = (float) ($row['planned_income'] ?? 0);
            $row['planned_need'] = $row['planned_expense'] - $row['planned_income'];
        }
        unset($row);
        return $rows;
    }

    /**
     * Budget + sections/items (ordered) + planned totals.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $budget = $this->db->fetch(
            'SELECT b.*, u.name AS approver_name
             FROM budgets b
             LEFT JOIN users u ON u.id = b.approved_by
             WHERE b.id = :id',
            ['id' => $id]
        );
        if ($budget === null) {
            return null;
        }
        $sections = $this->db->fetchAll(
            'SELECT * FROM budget_sections WHERE budget_id = :id ORDER BY sort_order ASC, id ASC',
            ['id' => $id]
        );
        $items = $this->db->fetchAll(
            'SELECT * FROM budget_items WHERE budget_id = :id ORDER BY sort_order ASC, id ASC',
            ['id' => $id]
        );

        $bySection = [];
        $orphans = [];
        foreach ($items as $item) {
            $sectionId = (int) ($item['section_id'] ?? 0);
            if ($sectionId > 0) {
                $bySection[$sectionId][] = $item;
            } else {
                $orphans[] = $item;
            }
        }
        $sectionRows = [];
        foreach ($sections as $section) {
            $sectionId = (int) $section['id'];
            $sectionItems = $bySection[$sectionId] ?? [];
            $sectionRows[] = [
                'id' => $sectionId,
                'title' => (string) ($section['title'] ?? ''),
                'items' => $sectionItems,
                'planned_expense' => $this->sumDirection($sectionItems, 'expense'),
                'planned_income' => $this->sumDirection($sectionItems, 'income'),
            ];
            unset($bySection[$sectionId]);
        }
        // Items whose section was removed stay visible under a generic bucket.
        foreach ($bySection as $leftover) {
            $orphans = array_merge($orphans, $leftover);
        }
        if ($orphans !== []) {
            $sectionRows[] = [
                'id' => 0,
                'title' => (string) __('budget.section_unassigned'),
                'items' => $orphans,
                'planned_expense' => $this->sumDirection($orphans, 'expense'),
                'planned_income' => $this->sumDirection($orphans, 'income'),
            ];
        }

        $budget['sections'] = $sectionRows;
        $budget['items'] = $items;
        $budget['planned_expense'] = $this->sumDirection($items, 'expense');
        $budget['planned_income'] = $this->sumDirection($items, 'income');
        $budget['planned_need'] = $budget['planned_expense'] - $budget['planned_income'];
        $budget['is_approved'] = (string) ($budget['status'] ?? '') === self::STATUS_APPROVED;

        return $budget;
    }

    /** @return array<string,mixed>|null */
    public function findByYear(int $year): ?array
    {
        $row = $this->db->fetch('SELECT id FROM budgets WHERE fiscal_year = :y', ['y' => $year]);
        return $row !== null ? $this->find((int) $row['id']) : null;
    }

    /** @param list<array<string,mixed>> $items */
    private function sumDirection(array $items, string $direction): float
    {
        $total = 0.0;
        foreach ($items as $item) {
            if ((string) ($item['direction'] ?? '') === $direction) {
                $total += (float) ($item['amount'] ?? 0);
            }
        }
        return $total;
    }

    /**
     * Create the year budget and optionally pre-load it from a template.
     *
     * @return array{ok:bool,id?:int,error?:string}
     */
    public function create(int $year, string $template, float $coefficient, string $title, string $ip, ?int $userId = null): array
    {
        if ($year < 2000 || $year > 2100) {
            return ['ok' => false, 'error' => 'year'];
        }
        if (!in_array($template, self::TEMPLATES, true)) {
            $template = 'empty';
        }
        if ($this->db->fetch('SELECT id FROM budgets WHERE fiscal_year = :y', ['y' => $year]) !== null) {
            return ['ok' => false, 'error' => 'exists'];
        }
        $coefficient = max(-50.0, min(50.0, $coefficient));
        $id = $this->db->insert('budgets', [
            'fiscal_year' => $year,
            'title' => $title !== '' ? mb_substr($title, 0, 160) : null,
            'status' => self::STATUS_DRAFT,
            'created_by' => $userId,
        ]);
        $this->seedTemplate($id, $year, $template, $coefficient);
        $this->audit->log('budget.created', 'budget', (string) $id, null, [
            'fiscal_year' => $year,
            'template' => $template,
            'coefficient' => $coefficient,
        ], $ip);
        return ['ok' => true, 'id' => $id];
    }

    private function seedTemplate(int $budgetId, int $year, string $template, float $coefficient): void
    {
        switch ($template) {
            case 'previous_budget':
                $this->seedFromPreviousBudget($budgetId, $year, $coefficient);
                break;
            case 'previous_actual':
                $this->seedFromPreviousActuals($budgetId, $year, $coefficient);
                break;
            case 'public_grant_model':
                $this->seedPublicGrantModel($budgetId);
                break;
        }
    }

    /** Copy last year's planned items, adjusted by ±N%. */
    private function seedFromPreviousBudget(int $budgetId, int $year, float $coefficient): void
    {
        $previous = $this->findByYear($year - 1);
        if ($previous === null) {
            return;
        }
        $factor = 1 + ($coefficient / 100);
        $sectionOrder = 0;
        $itemOrder = 0;
        foreach ($previous['sections'] as $section) {
            $items = $section['items'];
            if ($items === []) {
                continue;
            }
            $sectionOrder += 10;
            $sectionId = $this->db->insert('budget_sections', [
                'budget_id' => $budgetId,
                'title' => mb_substr((string) $section['title'], 0, 190),
                'sort_order' => $sectionOrder,
            ]);
            foreach ($items as $item) {
                $itemOrder += 10;
                $this->db->insert('budget_items', [
                    'budget_id' => $budgetId,
                    'section_id' => $sectionId,
                    'direction' => in_array((string) $item['direction'], self::DIRECTIONS, true) ? (string) $item['direction'] : 'expense',
                    'label' => mb_substr((string) $item['label'], 0, 190),
                    'amount' => round(((float) $item['amount']) * $factor, 2),
                    'category' => (string) ($item['category'] ?? '') !== '' ? (string) $item['category'] : null,
                    'notes' => (string) ($item['notes'] ?? '') !== '' ? (string) $item['notes'] : null,
                    'sort_order' => $itemOrder,
                ]);
            }
        }
    }

    /** Base the new plan on last year's real cash movements, grouped by category. */
    private function seedFromPreviousActuals(int $budgetId, int $year, float $coefficient): void
    {
        $rows = $this->db->fetchAll(
            "SELECT direction, category, COALESCE(SUM(amount), 0) AS total
             FROM treasury_movements
             WHERE YEAR(movement_date) = :y AND direction IN ('income', 'expense')
             GROUP BY direction, category
             ORDER BY direction ASC, total DESC",
            ['y' => $year - 1]
        );
        if ($rows === []) {
            return;
        }
        $factor = 1 + ($coefficient / 100);
        $buckets = ['expense' => [], 'income' => []];
        foreach ($rows as $row) {
            $direction = (string) $row['direction'];
            if (isset($buckets[$direction])) {
                $buckets[$direction][] = $row;
            }
        }
        $sectionOrder = 0;
        $itemOrder = 0;
        foreach (['expense', 'income'] as $direction) {
            if ($buckets[$direction] === []) {
                continue;
            }
            $sectionOrder += 10;
            $sectionId = $this->db->insert('budget_sections', [
                'budget_id' => $budgetId,
                'title' => (string) __(
                    $direction === 'expense' ? 'budget.tpl_from_actual_expenses' : 'budget.tpl_from_actual_income',
                    ['year' => (string) ($year - 1)]
                ),
                'sort_order' => $sectionOrder,
            ]);
            foreach ($buckets[$direction] as $row) {
                $itemOrder += 10;
                $category = trim((string) ($row['category'] ?? ''));
                $this->db->insert('budget_items', [
                    'budget_id' => $budgetId,
                    'section_id' => $sectionId,
                    'direction' => $direction,
                    'label' => mb_substr(
                        $category !== '' ? $this->treasury->categoryLabel($category) : (string) __('treasury.category_other'),
                        0,
                        190
                    ),
                    'amount' => round(((float) ($row['total'] ?? 0)) * $factor, 2),
                    'category' => $category !== '' ? $category : null,
                    'sort_order' => $itemOrder,
                ]);
            }
        }
    }

    /**
     * Ready-made plan for a public grant application: neutral categories that fit
     * any funding body (municipality, region, foundation, EU programme).
     */
    private function seedPublicGrantModel(int $budgetId): void
    {
        $model = [
            'budget.tpl_grant_section_running' => [
                ['budget.tpl_grant_rent', 'rent', 'expense'],
                ['budget.tpl_grant_utilities', 'utilities', 'expense'],
                ['budget.tpl_grant_consulting', 'services_consulting', 'expense'],
                ['budget.tpl_grant_staff', 'personnel', 'expense'],
            ],
            'budget.tpl_grant_section_activity' => [
                ['budget.tpl_grant_fees', 'services_consulting', 'expense'],
                ['budget.tpl_grant_travel', 'event_costs', 'expense'],
                ['budget.tpl_grant_services', 'equipment', 'expense'],
                ['budget.tpl_grant_materials', 'supplies', 'expense'],
            ],
            'budget.tpl_grant_section_income' => [
                ['budget.tpl_grant_membership', 'membership_fee', 'income'],
                ['budget.tpl_grant_public', 'public_grants', 'income'],
                ['budget.tpl_grant_sponsors', 'sponsorship', 'income'],
                ['budget.tpl_grant_event_income', 'events', 'income'],
            ],
        ];
        $sectionOrder = 0;
        $itemOrder = 0;
        foreach ($model as $titleKey => $items) {
            $sectionOrder += 10;
            $sectionId = $this->db->insert('budget_sections', [
                'budget_id' => $budgetId,
                'title' => (string) __($titleKey),
                'sort_order' => $sectionOrder,
            ]);
            foreach ($items as [$labelKey, $category, $direction]) {
                $itemOrder += 10;
                $this->db->insert('budget_items', [
                    'budget_id' => $budgetId,
                    'section_id' => $sectionId,
                    'direction' => $direction,
                    'label' => (string) __($labelKey),
                    'amount' => 0,
                    'category' => $category,
                    'sort_order' => $itemOrder,
                ]);
            }
        }
    }


    /**
     * Persist the inline grid (sections + rows) in one transaction.
     *
     * Posted shape (flat arrays, so the grid can add/remove rows client-side):
     * section_key[], section_id[], section_title[];
     * item_id[], item_section[], item_label[], item_category[], item_direction[],
     * item_amount[], item_notes[].
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool,error?:string}
     */
    public function saveGrid(int $id, array $input, string $ip): array
    {
        $budget = $this->find($id);
        if ($budget === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $existingSections = [];
        foreach ($this->db->fetchAll('SELECT id FROM budget_sections WHERE budget_id = :id', ['id' => $id]) as $row) {
            $existingSections[(int) $row['id']] = true;
        }
        $existingItems = [];
        foreach ($budget['items'] as $item) {
            $existingItems[(int) $item['id']] = true;
        }

        $sectionKeys = array_values((array) ($input['section_key'] ?? []));
        $sectionIds = array_values((array) ($input['section_id'] ?? []));
        $sectionTitles = array_values((array) ($input['section_title'] ?? []));
        $itemIds = array_values((array) ($input['item_id'] ?? []));
        $itemSections = array_values((array) ($input['item_section'] ?? []));
        $itemLabels = array_values((array) ($input['item_label'] ?? []));
        $itemCategories = array_values((array) ($input['item_category'] ?? []));
        $itemDirections = array_values((array) ($input['item_direction'] ?? []));
        $itemAmounts = array_values((array) ($input['item_amount'] ?? []));
        $itemNotes = array_values((array) ($input['item_notes'] ?? []));

        $this->db->beginTransaction();
        try {
            $keyToSectionId = [];
            $keptSections = [];
            $sectionIndex = 0;
            foreach ($sectionKeys as $index => $rawKey) {
                $key = trim((string) $rawKey);
                if ($key === '') {
                    $key = 's' . $index;
                }
                $title = mb_substr(trim((string) ($sectionTitles[$index] ?? '')), 0, 190);
                if ($title === '') {
                    $title = (string) __('budget.section_untitled');
                }
                $sectionIndex += 1;
                $sortOrder = $sectionIndex * 10;
                $sectionId = (int) ($sectionIds[$index] ?? 0);
                if ($sectionId > 0 && isset($existingSections[$sectionId]) && !in_array($sectionId, $keptSections, true)) {
                    $this->db->update('budget_sections', [
                        'title' => $title,
                        'sort_order' => $sortOrder,
                    ], 'id = :sid AND budget_id = :bid', ['sid' => $sectionId, 'bid' => $id]);
                } else {
                    $sectionId = $this->db->insert('budget_sections', [
                        'budget_id' => $id,
                        'title' => $title,
                        'sort_order' => $sortOrder,
                    ]);
                }
                $keptSections[] = $sectionId;
                $keyToSectionId[$key] = $sectionId;
            }


            $keptItems = [];
            $orderBySection = [];
            foreach ($itemIds as $index => $rawItemId) {
                $label = mb_substr(trim((string) ($itemLabels[$index] ?? '')), 0, 190);
                $amount = self::parseAmount((string) ($itemAmounts[$index] ?? ''));
                $itemId = (int) $rawItemId;
                if ($label === '' && $amount === 0.0 && $itemId < 1) {
                    continue; // untouched empty row
                }
                $sectionKey = trim((string) ($itemSections[$index] ?? ''));
                $sectionId = $keyToSectionId[$sectionKey] ?? null;
                $orderBySection[$sectionKey] = ($orderBySection[$sectionKey] ?? 0) + 10;
                $direction = (string) ($itemDirections[$index] ?? 'expense');
                if (!in_array($direction, self::DIRECTIONS, true)) {
                    $direction = 'expense';
                }
                $category = trim((string) ($itemCategories[$index] ?? ''));
                $notes = mb_substr(trim((string) ($itemNotes[$index] ?? '')), 0, 500);
                $payload = [
                    'section_id' => $sectionId,
                    'direction' => $direction,
                    'label' => $label !== '' ? $label : (string) __('budget.item_untitled'),
                    'amount' => $amount,
                    'category' => $category !== '' ? $category : null,
                    'notes' => $notes !== '' ? $notes : null,
                    'sort_order' => $orderBySection[$sectionKey],
                ];
                if ($itemId > 0 && isset($existingItems[$itemId])) {
                    $this->db->update('budget_items', $payload, 'id = :iid AND budget_id = :bid', ['iid' => $itemId, 'bid' => $id]);
                } else {
                    $payload['budget_id'] = $id;
                    $itemId = $this->db->insert('budget_items', $payload);
                }
                $keptItems[] = $itemId;
            }

            $this->deleteMissing('budget_items', $id, $keptItems);
            $this->deleteMissing('budget_sections', $id, $keptSections);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            app('logger')->error('budget.save_failed', ['budget' => $id, 'message' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'save_failed'];
        }

        $this->audit->log('budget.updated', 'budget', (string) $id, null, [
            'sections' => count($keptSections),
            'items' => count($keptItems),
        ], $ip);
        return ['ok' => true];
    }

    /** @param list<int> $keptIds */
    private function deleteMissing(string $table, int $budgetId, array $keptIds): void
    {
        if ($keptIds === []) {
            $this->db->query('DELETE FROM ' . $table . ' WHERE budget_id = :bid', ['bid' => $budgetId]);
            return;
        }
        $placeholders = [];
        $params = ['bid' => $budgetId];
        foreach (array_values(array_unique($keptIds)) as $i => $value) {
            $key = 'k' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }
        $this->db->query(
            'DELETE FROM ' . $table . ' WHERE budget_id = :bid AND id NOT IN (' . implode(', ', $placeholders) . ')',
            $params
        );
    }

    /** Parse "1.234,56" / "1234.56" / "1 234,56 €" into a decimal amount. */
    public static function parseAmount(string $value): float
    {
        $value = trim(str_replace(['€', "\u{00A0}", ' '], '', $value));
        if ($value === '') {
            return 0.0;
        }
        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }
        return round((float) $value, 2);
    }


    /** @return array{ok:bool,error?:string} */
    public function approve(int $id, string $ip, ?int $userId = null): array
    {
        $budget = $this->find($id);
        if ($budget === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $this->db->update('budgets', [
            'status' => self::STATUS_APPROVED,
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by' => $userId,
        ], 'id = :id', ['id' => $id]);
        $this->audit->log('budget.approved', 'budget', (string) $id, ['status' => $budget['status']], ['status' => self::STATUS_APPROVED], $ip);
        return ['ok' => true];
    }

    /** @return array{ok:bool,error?:string} */
    public function reopen(int $id, string $ip): array
    {
        $budget = $this->find($id);
        if ($budget === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $this->db->update('budgets', [
            'status' => self::STATUS_DRAFT,
            'approved_at' => null,
            'approved_by' => null,
        ], 'id = :id', ['id' => $id]);
        $this->audit->log('budget.reopened', 'budget', (string) $id, ['status' => $budget['status']], ['status' => self::STATUS_DRAFT], $ip);
        return ['ok' => true];
    }

    /** @return array{ok:bool,error?:string} */
    public function delete(int $id, string $ip): array
    {
        $budget = $this->find($id);
        if ($budget === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $this->db->query('DELETE FROM budgets WHERE id = :id', ['id' => $id]);
        $this->audit->log('budget.deleted', 'budget', (string) $id, [
            'fiscal_year' => $budget['fiscal_year'],
            'planned_expense' => $budget['planned_expense'],
            'planned_income' => $budget['planned_income'],
        ], null, $ip);
        return ['ok' => true];
    }


    /**
     * Preventivo vs consuntivo: per item, per section and global totals.
     *
     * `actual` counts only movements explicitly imputed to the item (any date:
     * a 2026 invoice paid in 2027 still belongs to the 2026 plan). Movements of
     * the fiscal year without imputation are listed separately, so totals always
     * reconcile with the cash book.
     *
     * @return array<string,mixed>
     */
    public function comparison(int $id): array
    {
        $budget = $this->find($id);
        if ($budget === null) {
            return [];
        }
        $itemIds = array_map(static fn (array $item): int => (int) $item['id'], $budget['items']);
        $actuals = [];
        if ($itemIds !== []) {
            $in = implode(', ', array_map('intval', $itemIds));
            foreach ($this->db->fetchAll(
                "SELECT budget_item_id, direction, COALESCE(SUM(amount), 0) AS total
                 FROM treasury_movements
                 WHERE budget_item_id IN (" . $in . ")
                 GROUP BY budget_item_id, direction"
            ) as $row) {
                $actuals[(int) $row['budget_item_id']][(string) $row['direction']] = (float) ($row['total'] ?? 0);
            }
        }

        $sections = [];
        $totals = [
            'planned_expense' => 0.0,
            'planned_income' => 0.0,
            'actual_expense' => 0.0,
            'actual_income' => 0.0,
            'unallocated_expense' => 0.0,
            'unallocated_income' => 0.0,
        ];
        foreach ($budget['sections'] as $section) {
            $rows = [];
            $plannedExpense = 0.0;
            $plannedIncome = 0.0;
            $actualExpense = 0.0;
            $actualIncome = 0.0;
            foreach ($section['items'] as $item) {
                $direction = (string) $item['direction'] === 'income' ? 'income' : 'expense';
                $planned = (float) ($item['amount'] ?? 0);
                $actual = (float) ($actuals[(int) $item['id']][$direction] ?? 0);
                $percent = $planned > 0 ? ($actual / $planned) * 100 : ($actual > 0 ? 100.0 : 0.0);
                $rows[] = [
                    'id' => (int) $item['id'],
                    'label' => (string) $item['label'],
                    'category' => (string) ($item['category'] ?? ''),
                    'notes' => (string) ($item['notes'] ?? ''),
                    'direction' => $direction,
                    'planned' => $planned,
                    'actual' => $actual,
                    'residual' => $planned - $actual,
                    'percent' => $percent,
                    'status' => self::budgetStatus($direction, $percent),
                ];
                if ($direction === 'income') {
                    $plannedIncome += $planned;
                    $actualIncome += $actual;
                } else {
                    $plannedExpense += $planned;
                    $actualExpense += $actual;
                }
            }
            $sections[] = [
                'id' => (int) $section['id'],
                'title' => (string) $section['title'],
                'items' => $rows,
                'planned_expense' => $plannedExpense,
                'planned_income' => $plannedIncome,
                'actual_expense' => $actualExpense,
                'actual_income' => $actualIncome,
                'planned_need' => $plannedExpense - $plannedIncome,
                'actual_need' => $actualExpense - $actualIncome,
                'residual_need' => ($plannedExpense - $plannedIncome) - ($actualExpense - $actualIncome),
            ];
            $totals['planned_expense'] += $plannedExpense;
            $totals['planned_income'] += $plannedIncome;
            $totals['actual_expense'] += $actualExpense;
            $totals['actual_income'] += $actualIncome;
        }

        $unallocated = [];
        foreach ($this->db->fetchAll(
            "SELECT direction, category, COUNT(*) AS movements, COALESCE(SUM(amount), 0) AS total
             FROM treasury_movements
             WHERE budget_item_id IS NULL
               AND YEAR(movement_date) = :y
               AND direction IN ('income', 'expense')
             GROUP BY direction, category
             ORDER BY direction ASC, total DESC",
            ['y' => (int) $budget['fiscal_year']]
        ) as $row) {
            $direction = (string) $row['direction'];
            $category = trim((string) ($row['category'] ?? ''));
            $total = (float) ($row['total'] ?? 0);
            $unallocated[] = [
                'direction' => $direction,
                'category' => $category,
                'label' => $category !== '' ? $this->treasury->categoryLabel($category) : (string) __('treasury.category_other'),
                'movements' => (int) ($row['movements'] ?? 0),
                'total' => $total,
            ];
            if ($direction === 'income') {
                $totals['unallocated_income'] += $total;
            } else {
                $totals['unallocated_expense'] += $total;
            }
        }

        $totals['planned_need'] = $totals['planned_expense'] - $totals['planned_income'];
        $totals['actual_need'] = $totals['actual_expense'] - $totals['actual_income'];
        $totals['actual_expense_all'] = $totals['actual_expense'] + $totals['unallocated_expense'];
        $totals['actual_income_all'] = $totals['actual_income'] + $totals['unallocated_income'];
        $totals['actual_need_all'] = $totals['actual_expense_all'] - $totals['actual_income_all'];
        $totals['expense_percent'] = $totals['planned_expense'] > 0
            ? ($totals['actual_expense'] / $totals['planned_expense']) * 100
            : 0.0;

        return [
            'budget' => $budget,
            'sections' => $sections,
            'unallocated' => $unallocated,
            'totals' => $totals,
            'alerts' => $this->overBudgetAlerts($sections),
        ];
    }


    /**
     * @param list<array<string,mixed>> $sections
     * @return list<array{label:string,over:float,percent:float}>
     */
    private function overBudgetAlerts(array $sections): array
    {
        $alerts = [];
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                if ($item['direction'] === 'expense' && $item['status'] === 'over') {
                    $alerts[] = [
                        'label' => (string) $item['label'],
                        'over' => (float) $item['actual'] - (float) $item['planned'],
                        'percent' => (float) $item['percent'],
                    ];
                }
            }
        }
        return $alerts;
    }

    /** Semaphore: expenses turn amber at 85% and red past the ceiling. */
    public static function budgetStatus(string $direction, float $percent): string
    {
        if ($direction === 'income') {
            return $percent >= 100 ? 'ok' : 'warning';
        }
        if ($percent > 100.5) {
            return 'over';
        }
        return $percent >= 85 ? 'warning' : 'ok';
    }

    /**
     * Budget items of a year, grouped by section — the "Centro di costo" select
     * of the movement form.
     *
     * @return list<array{label:string,options:list<array{value:string,label:string,direction:string,category:string}>}>
     */
    public function itemOptions(int $year): array
    {
        $budget = $this->findByYear($year);
        if ($budget === null) {
            return [];
        }
        $groups = [];
        foreach ($budget['sections'] as $section) {
            $options = [];
            foreach ($section['items'] as $item) {
                $options[] = [
                    'value' => (string) $item['id'],
                    'label' => (string) $item['label'],
                    'direction' => (string) $item['direction'],
                    'category' => (string) ($item['category'] ?? ''),
                ];
            }
            if ($options !== []) {
                $groups[] = ['label' => (string) $section['title'], 'options' => $options];
            }
        }
        return $groups;
    }

    public function itemExists(int $itemId): bool
    {
        if ($itemId < 1) {
            return false;
        }
        return $this->db->fetch('SELECT id FROM budget_items WHERE id = :id', ['id' => $itemId]) !== null;
    }

    public function itemLabel(int $itemId): string
    {
        if ($itemId < 1) {
            return '';
        }
        $row = $this->db->fetch(
            'SELECT i.label, b.fiscal_year
             FROM budget_items i
             INNER JOIN budgets b ON b.id = i.budget_id
             WHERE i.id = :id',
            ['id' => $itemId]
        );
        if ($row === null) {
            return '';
        }
        return (string) __('budget.item_ref', [
            'year' => (string) ($row['fiscal_year'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
        ]);
    }

    /**
     * Years offered when creating a new budget: current ±2 plus years already planned.
     *
     * @return list<int>
     */
    public function availableYears(): array
    {
        $current = (int) date('Y');
        $years = [$current + 1, $current, $current - 1];
        foreach ($this->db->fetchAll('SELECT fiscal_year FROM budgets ORDER BY fiscal_year DESC') as $row) {
            $years[] = (int) $row['fiscal_year'];
        }
        $years = array_values(array_unique($years));
        sort($years);
        return $years;
    }

    /**
     * Pezze giustificative per item (used by the rendiconto print sheet).
     *
     * @return array<int,list<array<string,mixed>>>
     */
    public function evidence(int $id): array
    {
        $budget = $this->find($id);
        if ($budget === null || $budget['items'] === []) {
            return [];
        }
        $ids = array_map(static fn (array $item): int => (int) $item['id'], $budget['items']);
        $in = implode(', ', array_map('intval', $ids));
        $out = [];
        foreach ($this->db->fetchAll(
            "SELECT budget_item_id, movement_date, direction, amount, description, invoice_number, invoice_date, beneficiary
             FROM treasury_movements
             WHERE budget_item_id IN (" . $in . ")
             ORDER BY movement_date ASC, id ASC"
        ) as $row) {
            $out[(int) $row['budget_item_id']][] = $row;
        }
        return $out;
    }

    /**
     * Import lines from a CSV file exported by Excel/LibreOffice.
     *
     * Recognised headers (IT/DE/EN): section · label · amount · type · category.
     * Without headers the positional order is section, label, amount [, type [, category]].
     *
     * @return array{ok:bool,imported:int,error?:string}
     */
    public function importCsv(int $budgetId, string $path): array
    {
        $budget = $this->find($budgetId);
        if ($budget === null) {
            return ['ok' => false, 'imported' => 0, 'error' => 'not_found'];
        }
        if (!is_file($path) || !is_readable($path)) {
            return ['ok' => false, 'imported' => 0, 'error' => 'unreadable'];
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ['ok' => false, 'imported' => 0, 'error' => 'unreadable'];
        }
        $rows = [];
        $delimiter = $this->csvDelimiter($path);
        while (($row = fgetcsv($handle, 4096, $delimiter)) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }
            $rows[] = array_map(static fn ($cell): string => trim((string) $cell), $row);
            if (count($rows) > 2000) {
                fclose($handle);
                return ['ok' => false, 'imported' => 0, 'error' => 'too_many_rows'];
            }
        }
        fclose($handle);
        if ($rows === []) {
            return ['ok' => false, 'imported' => 0, 'error' => 'empty'];
        }

        $headers = $this->csvHeaderMap($rows[0]);
        $start = $headers === null ? 0 : 1;
        if ($headers === null) {
            $headers = ['section' => 0, 'label' => 1, 'amount' => 2, 'type' => 3, 'category' => 4];
        }

        $sectionIds = [];
        $sectionOrder = 0;
        $itemOrder = 0;
        $imported = 0;
        foreach (array_slice($rows, $start) as $row) {
            $label = $this->csvCell($row, $headers, 'label');
            $amountRaw = $this->csvCell($row, $headers, 'amount');
            if ($label === '' && $amountRaw === '') {
                continue;
            }
            $amount = self::parseAmount($amountRaw);
            $direction = $this->csvDirection($this->csvCell($row, $headers, 'type'));
            $sectionTitle = $this->csvCell($row, $headers, 'section');
            if ($sectionTitle === '') {
                $sectionTitle = $direction === 'income'
                    ? (string) __('budget.tpl_from_actual_income', ['year' => (string) ($budget['fiscal_year'] ?? '')])
                    : (string) __('budget.section_untitled');
            }
            if (!isset($sectionIds[$sectionTitle])) {
                $sectionOrder += 10;
                $sectionIds[$sectionTitle] = $this->db->insert('budget_sections', [
                    'budget_id' => $budgetId,
                    'title' => mb_substr($sectionTitle, 0, 190),
                    'sort_order' => $sectionOrder,
                ]);
            }
            $itemOrder += 10;
            $category = mb_substr($this->csvCell($row, $headers, 'category'), 0, 80);
            $this->db->insert('budget_items', [
                'budget_id' => $budgetId,
                'section_id' => $sectionIds[$sectionTitle],
                'direction' => $direction,
                'label' => mb_substr($label !== '' ? $label : (string) __('budget.item_untitled'), 0, 190),
                'amount' => $amount,
                'category' => $category !== '' ? $category : null,
                'sort_order' => $itemOrder,
            ]);
            $imported += 1;
        }
        if ($imported === 0) {
            return ['ok' => false, 'imported' => 0, 'error' => 'empty'];
        }
        return ['ok' => true, 'imported' => $imported];
    }

    private function csvDelimiter(string $path): string
    {
        $first = '';
        $handle = fopen($path, 'r');
        if ($handle !== false) {
            $first = (string) fgets($handle);
            fclose($handle);
        }
        return substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    }

    /**
     * Map canonical field => column index when the first row looks like a header.
     *
     * @param list<string> $row
     * @return array<string,int>|null
     */
    private function csvHeaderMap(array $row): ?array
    {
        $map = [];
        foreach ($row as $index => $cell) {
            $key = strtolower(trim(str_replace(['"', "\u{FEFF}"], '', $cell)));
            $key = strtr($key, [
                'à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
                'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
            ]);
            $field = match (true) {
                str_contains($key, 'sezione'), str_contains($key, 'progetto'), str_contains($key, 'attivit'),
                str_contains($key, 'section'), str_contains($key, 'abschnitt') => 'section',
                str_contains($key, 'voce'), str_contains($key, 'descriz'), str_contains($key, 'capitolo'),
                str_contains($key, 'label'), str_contains($key, 'posten'), str_contains($key, 'line'),
                str_contains($key, 'libelle') => 'label',
                str_contains($key, 'importo'), str_contains($key, 'amount'), str_contains($key, 'betrag') => 'amount',
                str_contains($key, 'tipo'), str_contains($key, 'type'), str_contains($key, 'art') => 'type',
                str_contains($key, 'categoria'), str_contains($key, 'category'), str_contains($key, 'kategorie') => 'category',
                default => '',
            };
            if ($field !== '' && !isset($map[$field])) {
                $map[$field] = $index;
            }
        }
        return isset($map['label'], $map['amount']) ? $map : null;
    }

    /**
     * @param list<string> $row
     * @param array<string,int> $map
     */
    private function csvCell(array $row, array $map, string $field): string
    {
        $index = $map[$field] ?? null;
        return $index !== null && isset($row[$index]) ? trim((string) $row[$index]) : '';
    }

    private function csvDirection(string $raw): string
    {
        $raw = strtolower($raw);
        foreach (['entrat', 'income', 'einnahm', 'ricav', 'erlös'] as $needle) {
            if ($raw !== '' && str_contains($raw, $needle)) {
                return 'income';
            }
        }
        return 'expense';
    }
}

