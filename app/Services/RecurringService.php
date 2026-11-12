<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Core\Validator;

/**
 * Recurring journal entry operations.
 * The rule lives in `treasury_recurring`; when due, `runDue()` creates the entry for that period.
 * The amount can be fixed or variable (in which case it is a reminder to complete). Approval rule:
 * automatic entries on bank/card stay **to approve**, while cash ones are certain and get approved
 * immediately.
 */
final class RecurringService
{
    public const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'semiannual', 'yearly'];

    /**
     * Beyond this number of backward periods, burst movements are not generated.
     */
    private const MAX_CATCH_UP = 12;

    public function __construct(
        private readonly Database $db,
        private readonly Validator $validator,
        private readonly AuditService $audit
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM treasury_recurring ORDER BY is_active DESC, next_run_date ASC, id ASC'
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM treasury_recurring WHERE id = :id', ['id' => $id]);
    }

    /**
     * Create the rule starting from the data of the movement just inserted.
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool,id?:int}
     */
    public function createFromInput(array $input, string $anchorDate, ?int $userId = null, string $ip = 'system'): array
    {
        $frequency = (string) ($input['recurring_frequency'] ?? 'monthly');
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            $frequency = 'monthly';
        }
        $day = max(1, min(31, (int) ($input['recurring_day'] ?? 1)));
        $start = trim((string) ($input['recurring_start'] ?? ''));
        if ($start === '' || !$this->validator->validate(['d' => $start], ['d' => 'date'])) {
            $start = $anchorDate;
        }
        $endsOn = trim((string) ($input['recurring_ends'] ?? ''));
        if ($endsOn !== '' && !$this->validator->validate(['d' => $endsOn], ['d' => 'date'])) {
            $endsOn = '';
        }
        $next = $this->nextDate($start, $frequency, $day, true);
        $id = $this->db->insert('treasury_recurring', [
            'direction' => (string) ($input['direction'] ?? 'expense'),
            'amount' => round((float) ($input['amount'] ?? 0), 2),
            'amount_variable' => !empty($input['recurring_variable']) ? 1 : 0,
            'category' => trim((string) ($input['category'] ?? '')) ?: null,
            'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 500) ?: null,
            'account' => trim((string) ($input['account'] ?? '')) ?: null,
            'transfer_account' => trim((string) ($input['transfer_account'] ?? '')) ?: null,
            'payment_method' => trim((string) ($input['payment_method'] ?? '')) ?: null,
            'beneficiary' => mb_substr(trim((string) ($input['beneficiary'] ?? '')), 0, 190) ?: null,
            'member_id' => (int) ($input['member_id'] ?? 0) > 0 ? (int) $input['member_id'] : null,
            'budget_item_id' => (int) ($input['budget_item_id'] ?? 0) > 0 ? (int) $input['budget_item_id'] : null,
            'runts_line' => trim((string) ($input['runts_line'] ?? '')) ?: null,
            'frequency' => $frequency,
            'day_of_month' => $day,
            'next_run_date' => $next,
            'ends_on' => $endsOn !== '' ? $endsOn : null,
            'is_active' => 1,
            'created_by' => $userId,
        ]);
        $this->audit->log('treasury.recurring_created', 'treasury_recurring', (string) $id, null, [
            'frequency' => $frequency,
            'day' => $day,
            'next_run_date' => $next,
        ], $ip);

        return ['ok' => true, 'id' => $id];
    }

    public function setActive(int $id, bool $active, string $ip = 'system'): void
    {
        $this->db->update('treasury_recurring', ['is_active' => $active ? 1 : 0], 'id = :id', ['id' => $id]);
        $this->audit->log($active ? 'treasury.recurring_resumed' : 'treasury.recurring_paused', 'treasury_recurring', (string) $id, null, null, $ip);
    }

    public function delete(int $id, string $ip = 'system'): void
    {
        $this->db->query('DELETE FROM treasury_recurring WHERE id = :id', ['id' => $id]);
        $this->audit->log('treasury.recurring_deleted', 'treasury_recurring', (string) $id, null, null, $ip);
    }

    /**
     * Generate the due entries; returns how many were created.
     */
    public function runDue(string $ip = 'system', ?int $userId = null, ?string $today = null): int
    {
        $today = $today ?? date('Y-m-d');
        $rules = $this->db->fetchAll(
            'SELECT * FROM treasury_recurring WHERE is_active = 1 AND next_run_date <= :today ORDER BY next_run_date ASC',
            ['today' => $today]
        );
        $created = 0;
        foreach ($rules as $rule) {
            $ruleId = (int) $rule['id'];
            $next = (string) $rule['next_run_date'];
            $guard = 0;
            while ($next <= $today && $guard < self::MAX_CATCH_UP) {
                $guard++;
                if (!empty($rule['ends_on']) && $next > (string) $rule['ends_on']) {
                    $this->setActive($ruleId, false, $ip);
                    break;
                }
                if ($this->createMovement($rule, $next, $userId) > 0) {
                    $created++;
                }
                $following = $this->nextDate($next, (string) $rule['frequency'], (int) $rule['day_of_month']);
                $this->db->update('treasury_recurring', [
                    'last_run_date' => $next,
                    'next_run_date' => $following,
                ], 'id = :id', ['id' => $ruleId]);
                $next = $following;
            }
        }
        if ($created > 0) {
            $this->audit->log('treasury.recurring_generated', 'treasury_recurring', null, null, ['count' => $created], $ip);
        }

        return $created;
    }

    /**
     * Generated entry: approved at once only for cash; bank/card stay to approve.
     *
     * @param array<string,mixed> $rule
     */
    private function createMovement(array $rule, string $date, ?int $userId): int
    {
        $account = TreasuryService::normalizeAccount((string) ($rule['account'] ?? ''));
        if ($account === '') {
            $account = 'bank';
        }
        $approve = $account === 'cash';
        $description = trim((string) ($rule['description'] ?? ''));
        if ($description === '') {
            $description = (string) __('treasury.recurring_default_description');
        }
        if (!empty($rule['amount_variable'])) {
            $description .= ' · ' . (string) __('treasury.recurring_variable_note');
        }

        $marketMethod = TreasuryService::normalizePaymentMethod(trim((string) ($rule['payment_method'] ?? '')));
        if ($marketMethod === '') {
            $marketMethod = $account === 'cash' ? 'cash' : 'bank_transfer';
        }

        return $this->db->insert('treasury_movements', [
            'movement_date' => $date,
            'direction' => (string) ($rule['direction'] ?? 'expense'),
            'category' => (string) ($rule['category'] ?? 'other'),
            'amount' => round((float) ($rule['amount'] ?? 0), 2),
            'description' => mb_substr($description, 0, 500),
            'account' => $account,
            'payment_method' => $marketMethod,
            'beneficiary' => $rule['beneficiary'] ?? null,
            'member_id' => $rule['member_id'] ?? null,
            'budget_item_id' => $rule['budget_item_id'] ?? null,
            'runts_line' => $rule['runts_line'] ?? null,
            'recurring_id' => (int) $rule['id'],
            'approved_at' => $approve ? date('Y-m-d H:i:s') : null,
            'approved_by' => $approve ? $userId : null,
        ]);
    }

    /**
     * Next date after $from, aligned with the chosen day (or +7 days for the weekly frequency).
     */
    public function nextDate(string $from, string $frequency, int $dayOfMonth, bool $strictlyAfter = true): string
    {
        $ts = strtotime($from) ?: time();
        $day = max(1, min(31, $dayOfMonth));
        if ($frequency === 'weekly') {
            return date('Y-m-d', strtotime($strictlyAfter ? '+7 days' : '+0 days', $ts) ?: $ts);
        }
        $months = match ($frequency) {
            'quarterly' => 3,
            'semiannual' => 6,
            'yearly' => 12,
            default => 1,
        };
        $base = $strictlyAfter
            ? strtotime('first day of +' . $months . ' month', $ts)
            : strtotime('first day of this month', $ts);
        $base = $base ?: $ts;
        $year = (int) date('Y', $base);
        $month = (int) date('n', $base);
        $lastDay = (int) date('t', mktime(0, 0, 0, $month, 1, $year));

        return sprintf('%04d-%02d-%02d', $year, $month, min($day, $lastDay));
    }
}
