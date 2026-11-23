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

namespace Socly\Services;

use Socly\Core\Database;

/**
 * Budget estimate for financial year.
 * The final balance is not duplicated in a table: it is the sum of the cash book movements
 * attributed to the items (`treasury_movements.budget_item_id`), so the budget/final balance
 * comparison is always aligned with the ledger.
 */
final class BudgetService
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';

    /** @var list<string> */
    public const TEMPLATES = ['empty', 'previous_budget', 'previous_actual', 'public_grant_model'];

    /**
     * Nature of a budget line (“tipo”). The accounting sign stays in `direction`, so a type only
     * says what kind of post it is: a purchase of equipment is still an expense, a restricted
     * grant is still income.
     *
     * @var list<string>
     */
    public const KINDS = ['expense', 'investment', 'project', 'income', 'grant'];

    /** @var list<string> */
    private const DIRECTIONS = ['income', 'expense'];

    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
        private readonly TreasuryService $treasury,
        private readonly VersionService $versions
    ) {
    }

    /**
     * Budgets with planned totals, oldest year first (left → right on the Preventivo cards).
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
             ORDER BY b.fiscal_year ASC, b.id ASC"
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

    /** Accounting sign implied by a line type (investment is still an expense, grants are income). */
    public static function kindDirection(string $kind): string
    {
        return in_array($kind, ['income', 'grant'], true) ? 'income' : 'expense';
    }

    /** Normalize a stored/posted type, falling back to the accounting direction. */
    public static function normalizeKind(string $kind, string $direction = 'expense'): string
    {
        $kind = strtolower(trim($kind));
        if (in_array($kind, self::KINDS, true)) {
            return $kind;
        }
        return in_array($direction, self::DIRECTIONS, true) ? $direction : 'expense';
    }

    /** Translated label of a line type. */
    public static function kindLabel(string $kind): string
    {
        return (string) __('budget.kind_' . self::normalizeKind($kind));
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
                $direction = in_array((string) $item['direction'], self::DIRECTIONS, true) ? (string) $item['direction'] : 'expense';
                $this->db->insert('budget_items', [
                    'budget_id' => $budgetId,
                    'section_id' => $sectionId,
                    'direction' => $direction,
                    'kind' => self::normalizeKind((string) ($item['kind'] ?? ''), $direction),
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
             WHERE YEAR(movement_date) = :y AND approved_at IS NOT NULL AND superseded_at IS NULL AND direction IN ('income', 'expense')
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
                    'kind' => $direction,
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
                    'kind' => $category === 'public_grants' ? 'grant' : $direction,
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
     * item_id[], item_section[], item_category[], item_new_category[], item_kind[],
     * item_direction[] (legacy fallback), item_amount[], item_notes[].
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool,error?:string,message?:string,sections?:array<string,int>,items?:array<int,int>}
     */
    public function saveGrid(int $id, array $input, string $ip, ?int $expectedVersion = null, bool $force = false): array
    {
        $budget = $this->find($id);
        if ($budget === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        // Concurrency: Two operators on the same grid do not override each other.
        $expected = (int) ($expectedVersion ?? 0);
        if (!$force && $expected > 0 && (int) ($budget['row_version'] ?? 0) !== $expected) {
            return [
                'ok' => false,
                'conflict' => true,
                'current_version' => (int) ($budget['row_version'] ?? 0),
                'editor' => $this->versions->lastEditor('budget', $id),
            ];
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
        $itemNewCategories = array_values((array) ($input['item_new_category'] ?? []));
        $itemKinds = array_values((array) ($input['item_kind'] ?? []));
        $itemDirections = array_values((array) ($input['item_direction'] ?? []));
        $itemAmounts = array_values((array) ($input['item_amount'] ?? []));
        $itemNotes = array_values((array) ($input['item_notes'] ?? []));

        // Categories are resolved before the transaction: a new label registers a custom category,
        // and an invalid one must not leave half-saved rows behind.
        $existingCategories = [];
        foreach ($budget['items'] as $item) {
            $existingCategories[(int) $item['id']] = (string) ($item['category'] ?? '');
        }
        $resolvedCategories = [];
        foreach ($itemIds as $index => $rawItemId) {
            $rawCategory = trim((string) ($itemCategories[$index] ?? ''));
            if ($rawCategory === '') {
                $resolvedCategories[$index] = null;
                continue;
            }
            $resolved = $this->treasury->resolveCategoryChoice(
                $rawCategory,
                (string) ($itemNewCategories[$index] ?? ''),
                $existingCategories[(int) $rawItemId] ?? ''
            );
            if (empty($resolved['ok'])) {
                return [
                    'ok' => false,
                    'error' => 'category',
                    'message' => (string) ($resolved['error'] ?? __('validation.in')),
                ];
            }
            $resolvedCategories[$index] = (string) ($resolved['key'] ?? '');
        }

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
            $savedItemIds = [];
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
                $kind = self::normalizeKind(
                    (string) ($itemKinds[$index] ?? ''),
                    (string) ($itemDirections[$index] ?? 'expense')
                );
                $direction = self::kindDirection($kind);
                $category = $resolvedCategories[$index] ?? '';
                $notes = mb_substr(trim((string) ($itemNotes[$index] ?? '')), 0, 500);
                $payload = [
                    'section_id' => $sectionId,
                    'direction' => $direction,
                    'kind' => $kind,
                    'label' => $label !== '' ? $label : (string) __('budget.item_untitled'),
                    'amount' => $amount,
                    'category' => $category !== null && $category !== '' ? $category : null,
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
                $savedItemIds[$index] = $itemId;
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
        // The grid is saved: the version of the document is advanced (including autosave).
        $this->db->updateVersioned('budgets', [], 'id', $id, 0);
        return [
            'ok' => true,
            // Row/section ids for the AJAX autosave: the page keeps posting item_id[] values,
            // so freshly inserted rows must receive their new id to avoid duplicates.
            'sections' => $keyToSectionId,
            'items' => $savedItemIds,
            'row_version' => $this->db->rowVersion('budgets', 'id', $id),
        ];
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
     * Budget vs actuals: per item, per section and global totals.
     * `actual` counts only movements explicitly imputed to the item (any date: a 2026 invoice paid
     * in 2027 still belongs to the 2026 plan). Movements of the fiscal year without attribution are
     * listed separately, so totals always reconcile with the cash book.
     *
     * @return array<string,mixed>
     */
    public function comparison(int $id): array
    {
        $budget = $this->find($id);
        if ($budget === null) {
            return [];
        }
        $fiscalYear = (int) ($budget['fiscal_year'] ?? 0);
        // Keep consuntivo current: movements that already have the right category but no cost
        // centre are linked to the unique matching budget line for this fiscal year.
        $this->treasury->imputeUnallocatedByCategory($fiscalYear);

        // Association rule: the skeleton is the preventivo. Consuntivo only fills those lines,
        // aggregating approved cash/bank movements of THIS fiscal year (never invents chapters).
        $uniqueByCategory = [];
        foreach ($budget['items'] as $item) {
            $cat = trim((string) ($item['category'] ?? ''));
            if ($cat === '') {
                continue;
            }
            $dir = (string) $item['direction'] === 'income' ? 'income' : 'expense';
            $uniqueByCategory[$dir . '|' . $cat][] = (int) $item['id'];
        }
        $uniqueMap = [];
        foreach ($uniqueByCategory as $key => $ids) {
            if (count($ids) === 1) {
                $uniqueMap[$key] = $ids[0];
            }
        }

        $itemIds = array_map(static fn (array $item): int => (int) $item['id'], $budget['items']);
        $actuals = [];
        if ($itemIds !== [] && $fiscalYear >= 2000) {
            $in = implode(', ', array_map('intval', $itemIds));
            foreach ($this->db->fetchAll(
                "SELECT budget_item_id, direction, COALESCE(SUM(amount), 0) AS total
                 FROM treasury_movements
                 WHERE approved_at IS NOT NULL AND superseded_at IS NULL
                   AND budget_item_id IN (" . $in . ")
                   AND YEAR(movement_date) = :y
                   AND direction IN ('income', 'expense')
                 GROUP BY budget_item_id, direction",
                ['y' => $fiscalYear]
            ) as $row) {
                $actuals[(int) $row['budget_item_id']][(string) $row['direction']] = (float) ($row['total'] ?? 0);
            }
        }

        // Category fallback (same year): still count unallocated movements toward the unique line.
        if ($uniqueMap !== [] && $fiscalYear >= 2000) {
            foreach ($this->db->fetchAll(
                "SELECT direction, category, COALESCE(SUM(amount), 0) AS total
                 FROM treasury_movements
                 WHERE budget_item_id IS NULL
                   AND approved_at IS NOT NULL AND superseded_at IS NULL
                   AND YEAR(movement_date) = :y
                   AND direction IN ('income', 'expense')
                   AND category IS NOT NULL AND TRIM(category) <> ''
                 GROUP BY direction, category",
                ['y' => $fiscalYear]
            ) as $row) {
                $dir = (string) ($row['direction'] ?? '');
                $cat = trim((string) ($row['category'] ?? ''));
                $itemId = $uniqueMap[$dir . '|' . $cat] ?? 0;
                if ($itemId < 1) {
                    continue;
                }
                $actuals[$itemId][$dir] = (float) ($actuals[$itemId][$dir] ?? 0) + (float) ($row['total'] ?? 0);
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
                    'kind' => self::normalizeKind((string) ($item['kind'] ?? ''), $direction),
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

        // Movements of the year still outside every preventivo chapter (no matching line / ambiguous).
        $unallocated = [];
        $missingIncomeLines = 0;
        $missingExpenseLines = 0;
        foreach ($this->db->fetchAll(
            "SELECT direction, category, COUNT(*) AS movements, COALESCE(SUM(amount), 0) AS total
             FROM treasury_movements
             WHERE budget_item_id IS NULL
               AND approved_at IS NOT NULL AND superseded_at IS NULL
               AND YEAR(movement_date) = :y
               AND direction IN ('income', 'expense')
             GROUP BY direction, category
             ORDER BY direction ASC, total DESC",
            ['y' => $fiscalYear]
        ) as $row) {
            $direction = (string) $row['direction'];
            $category = trim((string) ($row['category'] ?? ''));
            $total = (float) ($row['total'] ?? 0);
            if ($category !== '' && isset($uniqueMap[$direction . '|' . $category])) {
                continue;
            }
            $hasBudgetLine = $category !== '' && isset($uniqueByCategory[$direction . '|' . $category]);
            $unallocated[] = [
                'direction' => $direction,
                'category' => $category,
                'label' => $category !== '' ? $this->treasury->categoryLabel($category) : (string) __('treasury.category_other'),
                'movements' => (int) ($row['movements'] ?? 0),
                'total' => $total,
                // Ambiguous = several preventivo lines share the category; missing = no line at all.
                'reason' => $hasBudgetLine ? 'ambiguous' : 'missing_line',
            ];
            if ($direction === 'income') {
                $totals['unallocated_income'] += $total;
                if (!$hasBudgetLine) {
                    $missingIncomeLines++;
                }
            } else {
                $totals['unallocated_expense'] += $total;
                if (!$hasBudgetLine) {
                    $missingExpenseLines++;
                }
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
        $totals['income_percent'] = $totals['planned_income'] > 0
            ? ($totals['actual_income'] / $totals['planned_income']) * 100
            : 0.0;
        // What the board asks: do this year’s actual incomes cover actual expenses?
        $totals['coverage'] = $totals['actual_expense'] > 0
            ? ($totals['actual_income'] / $totals['actual_expense']) * 100
            : ($totals['actual_income'] > 0 ? 100.0 : 0.0);
        $totals['missing_income_lines'] = $missingIncomeLines;
        $totals['missing_expense_lines'] = $missingExpenseLines;

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

    /**
     * Fiscal years that have a budget (ascending).
     *
     * @return list<int>
     */
    public function budgetYears(): array
    {
        $rows = $this->db->fetchAll('SELECT fiscal_year FROM budgets ORDER BY fiscal_year ASC');

        return array_values(array_map(static fn (array $row): int => (int) $row['fiscal_year'], $rows));
    }

    /** Fiscal year of a cost centre (0 when it no longer exists). */
    public function itemYear(int $itemId): int
    {
        if ($itemId < 1) {
            return 0;
        }
        $row = $this->db->fetch(
            'SELECT b.fiscal_year FROM budget_items i INNER JOIN budgets b ON b.id = i.budget_id WHERE i.id = :id',
            ['id' => $itemId]
        );

        return (int) ($row['fiscal_year'] ?? 0);
    }

    /** True when a preventivo exists for that fiscal year. */
    public function hasBudgetForYear(int $year): bool
    {
        return $year > 0 && $this->db->fetch('SELECT id FROM budgets WHERE fiscal_year = :y', ['y' => $year]) !== null;
    }

    /**
     * Closest budget year to the given one (the later one wins a tie), 0 when no budget exists.
     */
    private function nearestBudgetYear(int $year): int
    {
        $years = $this->budgetYears();
        if ($years === []) {
            return 0;
        }
        $best = $years[0];
        $bestDistance = abs($best - $year);
        foreach ($years as $candidate) {
            $distance = abs($candidate - $year);
            if ($distance < $bestDistance || ($distance === $bestDistance && $candidate > $best)) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * Map a cost centre onto the preventivo of `$year` when that year has a budget.
     * Same category (unique) wins; otherwise the same label in that year. Returns 0 when the year
     * has a budget but no counterpart — never keep a 2026 line on a 2025 movement.
     */
    public function alignItemToYear(int $itemId, int $year, string $category = '', string $direction = ''): int
    {
        if ($year < 1) {
            return max(0, $itemId);
        }
        if (!$this->hasBudgetForYear($year)) {
            // No preventivo for the movement year: keep the (labelled) fallback choice.
            return max(0, $itemId);
        }
        if ($itemId > 0 && $this->itemYear($itemId) === $year) {
            return $itemId;
        }
        $direction = $direction === 'income' ? 'income' : ($direction === 'expense' ? 'expense' : '');
        $category = trim($category);
        if ($category !== '' && $direction !== '') {
            try {
                $rows = $this->db->fetchAll(
                    'SELECT i.id
                     FROM budget_items i
                     INNER JOIN budgets b ON b.id = i.budget_id
                     WHERE b.fiscal_year = :y
                       AND i.direction = :dir
                       AND i.category = :cat
                     ORDER BY i.id ASC
                     LIMIT 3',
                    ['y' => $year, 'dir' => $direction, 'cat' => $category]
                );
            } catch (\Throwable) {
                $rows = [];
            }
            if (count($rows) === 1) {
                $id = (int) ($rows[0]['id'] ?? 0);
                if ($id > 0) {
                    return $id;
                }
            }
        }
        if ($itemId < 1) {
            return 0;
        }
        $source = $this->db->fetch(
            'SELECT i.label, i.direction FROM budget_items i WHERE i.id = :id',
            ['id' => $itemId]
        );
        $label = trim((string) ($source['label'] ?? ''));
        if ($label === '') {
            return 0;
        }
        $params = ['y' => $year, 'label' => $label];
        $dirSql = '';
        $sourceDir = (string) ($source['direction'] ?? '');
        if (in_array($sourceDir, ['income', 'expense'], true)) {
            $dirSql = ' AND i.direction = :dir';
            $params['dir'] = $sourceDir;
        }
        try {
            $matches = $this->db->fetchAll(
                'SELECT i.id
                 FROM budget_items i
                 INNER JOIN budgets b ON b.id = i.budget_id
                 WHERE b.fiscal_year = :y
                   AND i.label = :label' . $dirSql . '
                 ORDER BY i.id ASC
                 LIMIT 3',
                $params
            );
        } catch (\Throwable) {
            $matches = [];
        }
        if (count($matches) === 1) {
            $id = (int) ($matches[0]['id'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * Cost centres offered on a movement of the given year.
     *
     * The preventivo of the movement year is used whenever it exists. Only when that year has no
     * budget the closest one is offered instead (always labelled with its year). A cost centre
     * already assigned is kept only when it belongs to the listed budget (or the year has none).
     *
     * @return list<array{label:string,options:list<array{value:string,label:string,direction:string,category:string,year:string}>}>
     */
    public function itemOptionsForMovement(int $year, int $assignedItemId = 0): array
    {
        $sourceYear = $year;
        $groups = $this->itemOptions($year);
        if ($groups === []) {
            $fallbackYear = $this->nearestBudgetYear($year);
            if ($fallbackYear > 0 && $fallbackYear !== $year) {
                $sourceYear = $fallbackYear;
                $groups = $this->itemOptions($fallbackYear);
            }
        }
        foreach ($groups as $index => $group) {
            $section = trim((string) ($group['label'] ?? ''));
            $groups[$index]['label'] = $section !== ''
                ? $sourceYear . ' · ' . $section
                : (string) $sourceYear;
            foreach ($groups[$index]['options'] as $optIndex => $option) {
                $groups[$index]['options'][$optIndex]['year'] = (string) $sourceYear;
            }
        }
        if ($assignedItemId < 1) {
            return $groups;
        }
        foreach ($groups as $group) {
            foreach ($group['options'] as $option) {
                if ((int) ($option['value'] ?? 0) === $assignedItemId) {
                    return $groups;
                }
            }
        }
        // Wrong-year assignment while this year has a preventivo: do not re-offer the foreign line.
        if ($this->hasBudgetForYear($year) && $this->itemYear($assignedItemId) !== $year) {
            return $groups;
        }
        // No preventivo for the year (fallback list) or the line vanished: keep it selectable.
        $ownerYear = $this->itemYear($assignedItemId);
        if ($ownerYear < 1) {
            return $groups;
        }
        foreach ($this->itemOptions($ownerYear) as $group) {
            foreach ($group['options'] as $option) {
                if ((int) ($option['value'] ?? 0) === $assignedItemId) {
                    $option['year'] = (string) $ownerYear;
                    $section = trim((string) ($group['label'] ?? ''));
                    $groups[] = [
                        'label' => $section !== '' ? $ownerYear . ' · ' . $section : (string) $ownerYear,
                        'options' => [$option],
                    ];
                    return $groups;
                }
            }
        }

        return $groups;
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
     * Years that can still receive a budget: one document per social year, so the years that
     * already have a draft or approved budget are excluded.
     *
     * @return list<int>
     */
    public function creatableYears(): array
    {
        $taken = [];
        foreach ($this->listDocuments() as $row) {
            $taken[(int) ($row['fiscal_year'] ?? 0)] = true;
        }
        return array_values(array_filter(
            $this->availableYears(),
            static fn (int $year): bool => !isset($taken[$year])
        ));
    }

    /**
     * Receipts (“pezze giustificative”) of an approved budget, grouped by cost centre.
     *
     * Only the movements that actually carry a document are listed — one with an invoice/receipt
     * number or a document type (invoice, receipt, quittance, …). An entry without any document is
     * not a supporting paper and must not appear in the printed statement. Each receipt carries its
     * stable code (P + movement number) plus document date, document number, payment date and
     * counterparty, so the statement reads like a proper appendix.
     *
     * @return array<int,list<array{id:int,code:string,movement_date:string,amount:float,invoice_number:string,invoice_date:string,counterparty:string}>>
     */
    public function evidence(int $id): array
    {
        $budget = $this->find($id);
        if ($budget === null || $budget['items'] === []) {
            return [];
        }
        $ids = array_map(static fn (array $item): int => (int) $item['id'], $budget['items']);
        $in = implode(', ', array_map('intval', $ids));
        $rows = $this->db->fetchAll(
            "SELECT m.budget_item_id, m.id, m.movement_date, m.amount, m.invoice_number, m.invoice_date,
                    m.justification_code, m.beneficiary, m.member_id
             FROM treasury_movements m
             WHERE m.approved_at IS NOT NULL AND m.superseded_at IS NULL AND m.budget_item_id IN (" . $in . ")
               AND (
                   (m.invoice_number IS NOT NULL AND TRIM(m.invoice_number) <> '')
                   OR (m.document_type IS NOT NULL AND TRIM(m.document_type) <> '')
               )
             ORDER BY m.movement_date ASC, m.id ASC"
        );
        $memberIds = [];
        foreach ($rows as $row) {
            $memberId = (int) ($row['member_id'] ?? 0);
            if ($memberId > 0) {
                $memberIds[] = $memberId;
            }
        }
        $memberNames = $memberIds === [] ? [] : $this->treasury->memberDisplayNames($memberIds);
        $out = [];
        foreach ($rows as $row) {
            $memberId = (int) ($row['member_id'] ?? 0);
            $counterparty = trim((string) ($memberNames[$memberId] ?? ''));
            if ($counterparty === '') {
                $counterparty = trim((string) ($row['beneficiary'] ?? ''));
            }
            $code = trim((string) ($row['justification_code'] ?? ''));
            if ($code === '') {
                $code = TreasuryService::justificationCode((int) ($row['id'] ?? 0));
            }
            $out[(int) $row['budget_item_id']][] = [
                'id' => (int) ($row['id'] ?? 0),
                'code' => $code,
                'movement_date' => (string) ($row['movement_date'] ?? ''),
                'amount' => (float) ($row['amount'] ?? 0),
                'invoice_number' => trim((string) ($row['invoice_number'] ?? '')),
                'invoice_date' => trim((string) ($row['invoice_date'] ?? '')),
                'counterparty' => $counterparty,
            ];
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
            $resolvedCategory = $this->treasury->resolveCategoryFromLabel($category);
            if ($resolvedCategory !== null) {
                $category = $resolvedCategory;
            }
            $rawType = $this->csvCell($row, $headers, 'type');
            $this->db->insert('budget_items', [
                'budget_id' => $budgetId,
                'section_id' => $sectionIds[$sectionTitle],
                'direction' => $direction,
                'kind' => $this->csvKind($rawType, $direction),
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

    /** Map the CSV “tipo” column to a line type, falling back to the accounting sign. */
    private function csvKind(string $raw, string $direction): string
    {
        $raw = strtolower(trim($raw));
        $map = [
            'investment' => ['investiment', 'investment', 'investition', 'conto capitale'],
            'project' => ['progett', 'project', 'projekt', 'attivit'],
            'grant' => ['contribut', 'grant', 'zuschuss', 'fondo perduto'],
        ];
        foreach ($map as $kind => $needles) {
            foreach ($needles as $needle) {
                if ($raw !== '' && str_contains($raw, $needle)) {
                    return self::kindDirection($kind) === $direction ? $kind : $direction;
                }
            }
        }
        return $direction;
    }

    /**
     * Statistics useful for the association: planned vs final balance per year, expense coverage,
     * membership share on total revenue and main categories of the last financial year.
     *
     * @return array<string,mixed>
     */
    public function statistics(int $maxYears = 5): array
    {
        $planned = [];
        foreach ($this->db->fetchAll(
            "SELECT b.fiscal_year AS y,
                    COALESCE(SUM(CASE WHEN i.direction = 'expense' THEN i.amount ELSE 0 END), 0) AS expense,
                    COALESCE(SUM(CASE WHEN i.direction = 'income' THEN i.amount ELSE 0 END), 0) AS income
             FROM budgets b
             LEFT JOIN budget_items i ON i.budget_id = b.id
             GROUP BY b.fiscal_year"
        ) as $row) {
            $planned[(int) $row['y']] = ['expense' => (float) $row['expense'], 'income' => (float) $row['income']];
        }

        $actual = [];
        foreach ($this->db->fetchAll(
            "SELECT YEAR(movement_date) AS y, direction, COALESCE(SUM(amount), 0) AS total
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL AND direction IN ('income', 'expense')
             GROUP BY y, direction"
        ) as $row) {
            $actual[(int) $row['y']][(string) $row['direction']] = (float) $row['total'];
        }

        $years = array_values(array_unique(array_merge(array_keys($planned), array_keys($actual))));
        rsort($years);
        $years = array_slice($years, 0, max(1, $maxYears));
        sort($years);

        $rows = [];
        foreach ($years as $year) {
            $plan = $planned[$year] ?? ['expense' => 0.0, 'income' => 0.0];
            $expense = (float) ($actual[$year]['expense'] ?? 0.0);
            $income = (float) ($actual[$year]['income'] ?? 0.0);
            $rows[] = [
                'year' => $year,
                'planned_expense' => round($plan['expense'], 2),
                'planned_income' => round($plan['income'], 2),
                'actual_expense' => round($expense, 2),
                'actual_income' => round($income, 2),
                'expense_delta' => round($expense - $plan['expense'], 2),
                'income_delta' => round($income - $plan['income'], 2),
                'need' => round($expense - $income, 2),
                'coverage' => $expense > 0 ? round($income * 100 / $expense, 1) : ($income > 0 ? 100.0 : 0.0),
            ];
        }

        // The statistics by category look at the last financial year with real movements, not the year of the
        // draft estimate.
        $latest = (int) date('Y');
        foreach (array_reverse($years) as $year) {
            if (!empty($actual[$year])) {
                $latest = (int) $year;
                break;
            }
        }
        $byCategory = ['expense' => [], 'income' => []];
        foreach ($this->db->fetchAll(
            "SELECT category, direction, COALESCE(SUM(amount), 0) AS total
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL AND direction IN ('income', 'expense')
               AND YEAR(movement_date) = :y
             GROUP BY category, direction
             ORDER BY total DESC",
            ['y' => $latest]
        ) as $row) {
            $direction = (string) $row['direction'] === 'income' ? 'income' : 'expense';
            $amount = (float) ($row['total'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $key = trim((string) ($row['category'] ?? ''));
            $byCategory[$direction][] = [
                'key' => $key,
                'label' => $key !== '' ? $this->treasury->categoryLabel($key) : (string) __('treasury.category_other'),
                'amount' => round($amount, 2),
            ];
        }
        foreach (['expense', 'income'] as $direction) {
            $total = array_sum(array_column($byCategory[$direction], 'amount'));
            $byCategory[$direction] = array_slice($byCategory[$direction], 0, 6);
            foreach ($byCategory[$direction] as &$category) {
                $category['percent'] = $total > 0 ? round($category['amount'] * 100 / $total, 1) : 0.0;
            }
            unset($category);
        }

        $fees = (float) ($this->db->fetch(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL AND direction = 'income' AND category = 'membership_fee'
               AND YEAR(movement_date) = :y",
            ['y' => $latest]
        )['total'] ?? 0);
        $incomeTotal = (float) ($actual[$latest]['income'] ?? 0.0);

        return [
            'years' => $rows,
            'latest_year' => $latest,
            'categories' => $byCategory,
            'fees' => round($fees, 2),
            'fees_share' => $incomeTotal > 0 ? round($fees * 100 / $incomeTotal, 1) : 0.0,
        ];
    }

    /**
     * Full annual balance for a fiscal year: preventivo vs consuntivo, categories, fees, monthly
     * flow, account snapshot and RUNTS section totals — material suitable for a public presentation.
     *
     * @return array<string,mixed>
     */
    public function annualReport(int $year, ?RuntsReportService $runts = null): array
    {
        $available = $this->availableReportYears();
        if ($year < 2000) {
            $year = (int) ($available[0] ?? date('Y'));
        }
        if ($available !== [] && !in_array($year, $available, true)) {
            $year = (int) $available[0];
        }

        $planned = ['expense' => 0.0, 'income' => 0.0];
        $budget = $this->findByYear($year);
        if ($budget !== null) {
            $plannedRow = $this->db->fetch(
                "SELECT
                    COALESCE(SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END), 0) AS expense,
                    COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END), 0) AS income
                 FROM budget_items WHERE budget_id = :id",
                ['id' => (int) $budget['id']]
            );
            $planned = [
                'expense' => round((float) ($plannedRow['expense'] ?? 0), 2),
                'income' => round((float) ($plannedRow['income'] ?? 0), 2),
            ];
        }

        $actualRow = $this->db->fetch(
            "SELECT
                COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END), 0) AS expense,
                COUNT(*) AS movements
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL
               AND direction IN ('income', 'expense') AND YEAR(movement_date) = :y",
            ['y' => $year]
        );
        $actual = [
            'income' => round((float) ($actualRow['income'] ?? 0), 2),
            'expense' => round((float) ($actualRow['expense'] ?? 0), 2),
            'movements' => (int) ($actualRow['movements'] ?? 0),
        ];
        $result = round($actual['income'] - $actual['expense'], 2);
        $coverage = $actual['expense'] > 0
            ? round($actual['income'] * 100 / $actual['expense'], 1)
            : ($actual['income'] > 0 ? 100.0 : 0.0);

        $categories = ['expense' => [], 'income' => []];
        foreach ($this->db->fetchAll(
            "SELECT category, direction, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL
               AND direction IN ('income', 'expense') AND YEAR(movement_date) = :y
             GROUP BY category, direction
             ORDER BY total DESC",
            ['y' => $year]
        ) as $row) {
            $direction = (string) $row['direction'] === 'income' ? 'income' : 'expense';
            $amount = (float) ($row['total'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $key = trim((string) ($row['category'] ?? ''));
            $categories[$direction][] = [
                'key' => $key,
                'label' => $key !== '' ? $this->treasury->categoryLabel($key) : (string) __('treasury.category_other'),
                'amount' => round($amount, 2),
                'count' => (int) ($row['cnt'] ?? 0),
            ];
        }
        foreach (['expense', 'income'] as $direction) {
            $total = array_sum(array_column($categories[$direction], 'amount'));
            foreach ($categories[$direction] as &$category) {
                $category['percent'] = $total > 0 ? round($category['amount'] * 100 / $total, 1) : 0.0;
            }
            unset($category);
        }

        $fees = (float) ($this->db->fetch(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL AND direction = 'income'
               AND category = 'membership_fee' AND YEAR(movement_date) = :y",
            ['y' => $year]
        )['total'] ?? 0);

        $monthly = ['labels' => [], 'income' => [], 'expense' => []];
        $byMonth = [];
        foreach ($this->db->fetchAll(
            "SELECT MONTH(movement_date) AS m,
                    COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END), 0) AS inc,
                    COALESCE(SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END), 0) AS exp
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL
               AND direction IN ('income', 'expense') AND YEAR(movement_date) = :y
             GROUP BY m",
            ['y' => $year]
        ) as $row) {
            $byMonth[(int) ($row['m'] ?? 0)] = [(float) ($row['inc'] ?? 0), (float) ($row['exp'] ?? 0)];
        }
        for ($month = 1; $month <= 12; $month++) {
            $monthly['labels'][] = (string) __('common.month_' . $month);
            $monthly['income'][] = round($byMonth[$month][0] ?? 0.0, 2);
            $monthly['expense'][] = round($byMonth[$month][1] ?? 0.0, 2);
        }

        $opening = (float) ($this->db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'income' THEN amount WHEN direction = 'expense' THEN -amount ELSE 0 END), 0) AS net
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL AND movement_date < :from",
            ['from' => sprintf('%04d-01-01', $year)]
        )['net'] ?? 0);
        $closing = round($opening + $actual['income'] - $actual['expense'], 2);

        $accounts = $this->treasury->accountBalances([
            'from' => sprintf('%04d-01-01', $year),
            'to' => sprintf('%04d-12-31', $year),
        ]);

        $runtsSections = [];
        if ($runts instanceof RuntsReportService) {
            try {
                $report = $runts->report($year);
                foreach (($report['sections'] ?? []) as $letter => $section) {
                    if (!is_array($section)) {
                        continue;
                    }
                    $totals = is_array($section['totals'] ?? null) ? $section['totals'] : [];
                    $runtsSections[] = [
                        'letter' => (string) $letter,
                        'label' => (string) ($section['label'] ?? $letter),
                        'expense' => round((float) ($totals['expense'] ?? 0), 2),
                        'income' => round((float) ($totals['income'] ?? 0), 2),
                    ];
                }
            } catch (\Throwable) {
                $runtsSections = [];
            }
        }

        $charts = [
            'flow' => [
                'labels' => [__('treasury.direction_income'), __('treasury.direction_expense')],
                'values' => [$actual['income'], $actual['expense']],
            ],
            'expense_by_category' => [
                'labels' => array_column(array_slice($categories['expense'], 0, 8), 'label'),
                'values' => array_column(array_slice($categories['expense'], 0, 8), 'amount'),
            ],
            'income_by_category' => [
                'labels' => array_column(array_slice($categories['income'], 0, 8), 'label'),
                'values' => array_column(array_slice($categories['income'], 0, 8), 'amount'),
            ],
            'monthly' => $monthly,
        ];

        return [
            'year' => $year,
            'available_years' => $available,
            'budget' => $budget,
            'planned' => $planned,
            'actual' => $actual,
            'result' => $result,
            'coverage' => $coverage,
            'need' => round($actual['expense'] - $actual['income'], 2),
            'fees' => round($fees, 2),
            'fees_share' => $actual['income'] > 0 ? round($fees * 100 / $actual['income'], 1) : 0.0,
            'categories' => $categories,
            'monthly' => $monthly,
            'opening_balance' => round($opening, 2),
            'closing_balance' => $closing,
            'accounts' => $accounts,
            'runts_sections' => $runtsSections,
            'charts' => $charts,
            'history' => $this->statistics(5)['years'],
        ];
    }

    /**
     * Years that have a budget and/or approved cash movements.
     *
     * @return list<int>
     */
    public function availableReportYears(): array
    {
        $years = [];
        foreach ($this->db->fetchAll('SELECT DISTINCT fiscal_year AS y FROM budgets ORDER BY y DESC') as $row) {
            $year = (int) ($row['y'] ?? 0);
            if ($year >= 2000) {
                $years[$year] = true;
            }
        }
        foreach ($this->db->fetchAll(
            'SELECT DISTINCT YEAR(movement_date) AS y FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL ORDER BY y DESC'
        ) as $row) {
            $year = (int) ($row['y'] ?? 0);
            if ($year >= 2000) {
                $years[$year] = true;
            }
        }
        $list = array_keys($years);
        rsort($list);

        return $list;
    }
}

