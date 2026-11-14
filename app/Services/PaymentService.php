<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Core\Plugin\PluginManager;
use Socly\Core\Validator;

final class PaymentService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
        private readonly Validator $validator,
        private readonly PluginManager $plugins,
        private readonly TreasuryService $treasury
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function forMember(int $memberId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT p.*, u.name AS created_by_name,
                    tm.id AS movement_id, tm.account AS movement_account,
                    tm.movement_date AS movement_date, tm.amount AS movement_amount,
                    tm.approved_at AS movement_approved_at
             FROM payments p
             LEFT JOIN users u ON u.id = p.created_by
             LEFT JOIN treasury_movements tm ON tm.payment_id = p.id AND tm.superseded_at IS NULL
             WHERE p.member_id = :id
             ORDER BY p.created_at DESC',
            ['id' => $memberId]
        );
        // The member card must show at a glance whether the quota reached the cash book: a payment
        // with no movement (or with a different amount) gets the warning mark and the fix action.
        foreach ($rows as &$row) {
            $movementId = (int) ($row['movement_id'] ?? 0);
            $type = (string) ($row['type'] ?? '');
            $row['movement_id'] = $movementId;
            $row['treasury_state'] = 'none';
            if (in_array($type, ['membership', 'debt'], true)) {
                if ($movementId < 1) {
                    $row['treasury_state'] = 'missing';
                } elseif (abs((float) ($row['movement_amount'] ?? 0) - (float) ($row['amount'] ?? 0)) > 0.005) {
                    $row['treasury_state'] = 'mismatch';
                } elseif (trim((string) ($row['movement_approved_at'] ?? '')) === ''
                    && TreasuryService::requiresApproval((string) ($row['movement_account'] ?? ''))) {
                    // Bank/other wallets still wait for the first-note approval: the movement exists
                    // but it does not count towards the balance yet.
                    $row['treasury_state'] = 'pending';
                } else {
                    $row['treasury_state'] = 'ok';
                }
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Books a recorded payment into the cash book (the “register” action on the member card).
     *
     * @return array{ok:bool,message:string}
     */
    public function registerTreasuryForPayment(int $memberId, int $paymentId, string $ip): array
    {
        $payment = $this->db->fetch(
            'SELECT * FROM payments WHERE id = :id AND member_id = :member',
            ['id' => $paymentId, 'member' => $memberId]
        );
        if ($payment === null) {
            return ['ok' => false, 'message' => (string) __('payments.not_found')];
        }
        if (!in_array((string) ($payment['type'] ?? ''), ['membership', 'debt'], true)) {
            return ['ok' => false, 'message' => (string) __('payments.treasury_not_applicable')];
        }
        $existing = $this->db->fetch(
            'SELECT id FROM treasury_movements WHERE payment_id = :payment AND superseded_at IS NULL LIMIT 1',
            ['payment' => $paymentId]
        );
        if ($existing !== null) {
            return ['ok' => true, 'message' => (string) __('payments.treasury_already')];
        }
        if (!component_enabled('treasury')) {
            return ['ok' => false, 'message' => (string) __('payments.treasury_failed')];
        }
        $date = substr(trim((string) ($payment['created_at'] ?? '')), 0, 10);
        $this->treasury->registerMembershipIncome($paymentId, $memberId, [
            'amount' => (float) ($payment['amount'] ?? 0),
            'movement_date' => $date !== '' ? $date : date('Y-m-d'),
            'payment_method' => (string) ($payment['method'] ?? 'cash'),
            'description' => (string) __('treasury.auto_membership_payment'),
            'category' => 'membership_fee',
        ], auth_user()['id'] ?? null);
        $movement = $this->db->fetch(
            'SELECT id FROM treasury_movements WHERE payment_id = :payment ORDER BY id DESC LIMIT 1',
            ['payment' => $paymentId]
        );
        if ($movement === null) {
            return ['ok' => false, 'message' => (string) __('payments.treasury_failed')];
        }
        $this->audit->log('payment.treasury_registered', 'payment', (string) $paymentId, null, [
            'movement_id' => (int) $movement['id'],
            'amount' => (float) ($payment['amount'] ?? 0),
        ], $ip);

        return ['ok' => true, 'message' => (string) __('payments.treasury_registered')];
    }

    /**
     * Removes the cash-book movement linked to a payment. The payment itself stays recorded: only
     * the ledger row goes away, after the confirmation in the UI.
     *
     * @return array{ok:bool,message:string}
     */
    public function deleteTreasuryForPayment(int $memberId, int $paymentId, string $ip): array
    {
        $payment = $this->db->fetch(
            'SELECT id FROM payments WHERE id = :id AND member_id = :member',
            ['id' => $paymentId, 'member' => $memberId]
        );
        if ($payment === null) {
            return ['ok' => false, 'message' => (string) __('payments.not_found')];
        }
        $movement = $this->db->fetch(
            'SELECT id, amount, movement_date FROM treasury_movements
              WHERE payment_id = :payment AND member_id = :member AND superseded_at IS NULL
              ORDER BY id DESC LIMIT 1',
            ['payment' => $paymentId, 'member' => $memberId]
        );
        if ($movement === null) {
            return ['ok' => false, 'message' => (string) __('payments.treasury_missing_movement')];
        }
        $movementId = (int) ($movement['id'] ?? 0);
        if (!$this->treasury->delete($movementId, $ip, auth_user()['id'] ?? null)) {
            return ['ok' => false, 'message' => (string) __('payments.treasury_delete_failed')];
        }
        $this->audit->log('payment.treasury_deleted', 'payment', (string) $paymentId, [
            'movement_id' => $movementId,
            'amount' => (float) ($movement['amount'] ?? 0),
            'movement_date' => (string) ($movement['movement_date'] ?? ''),
        ], null, $ip);

        return ['ok' => true, 'message' => (string) __('payments.treasury_deleted')];
    }

    /** @return array{ok:bool,errors?:array} */
    public function record(int $memberId, array $data, string $ip): array
    {
        $member = $this->db->fetch('SELECT * FROM members WHERE id = :id', ['id' => $memberId]);
        if (!$member) {
            return ['ok' => false, 'errors' => ['member_id' => __('validation.required')]];
        }
        if (!$this->validator->validate($data, [
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|in:cash,bank,other',
            'type' => 'required|in:membership,debt,adjustment',
        ])) {
            return ['ok' => false, 'errors' => $this->validator->firstErrors()];
        }

        $amount = round((float) $data['amount'], 2);
        // Payment date: defaults to today, but the real (possibly past) date is accepted so
        // charts and the first note stay aligned with reality.
        $paidOn = trim((string) ($data['paid_on'] ?? ''));
        if ($paidOn !== '' && !$this->validator->validate(['paid_on' => $paidOn], ['paid_on' => 'date'])) {
            return ['ok' => false, 'errors' => $this->validator->firstErrors()];
        }
        if ($paidOn === '') {
            $paidOn = date('Y-m-d');
        }
        $before = $member;
        $newBalance = max(0, round((float) $member['balance_due'] - $amount, 2));
        if ($data['type'] === 'adjustment' && !empty($data['set_balance'])) {
            $newBalance = max(0, round((float) $data['set_balance'], 2));
        }

        $this->db->beginTransaction();
        try {
            $paymentId = $this->db->insert('payments', [
                'member_id' => $memberId,
                'amount' => $amount,
                'method' => $data['method'],
                'type' => $data['type'],
                'note' => $data['note'] ?? null,
                'created_by' => auth_user()['id'] ?? null,
                'created_at' => $paidOn . ' 12:00:00',
            ]);
            $this->db->update('members', ['balance_due' => $newBalance], 'id = :id', ['id' => $memberId]);
            if (in_array($data['type'], ['membership', 'debt'], true)) {
                $this->treasury->autoRegisterFromPayment(
                    (int) $paymentId,
                    $memberId,
                    $amount,
                    (string) $data['method'],
                    $paidOn,
                    auth_user()['id'] ?? null
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $payment = $this->db->fetch('SELECT * FROM payments WHERE id = :id', ['id' => $paymentId]);
        $after = $this->db->fetch('SELECT * FROM members WHERE id = :id', ['id' => $memberId]);
        $this->audit->log('payment.recorded', 'payment', (string) $paymentId, $before, $after, $ip);
        $this->plugins->fire('payment.recorded', $payment);
        return ['ok' => true];
    }
}
