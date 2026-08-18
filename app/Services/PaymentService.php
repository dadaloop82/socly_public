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
        return $this->db->fetchAll(
            'SELECT p.*, u.name AS created_by_name
             FROM payments p
             LEFT JOIN users u ON u.id = p.created_by
             WHERE p.member_id = :id
             ORDER BY p.created_at DESC',
            ['id' => $memberId]
        );
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
            ]);
            $this->db->update('members', ['balance_due' => $newBalance], 'id = :id', ['id' => $memberId]);
            if (in_array($data['type'], ['membership', 'debt'], true)) {
                $this->treasury->autoRegisterFromPayment(
                    (int) $paymentId,
                    $memberId,
                    $amount,
                    (string) $data['method'],
                    date('Y-m-d')
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
