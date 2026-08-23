<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;

final class MemberActionService
{
    public function __construct(
        private readonly Database $db,
        private readonly MemberService $members,
        private readonly PaymentService $payments,
        private readonly WorkflowService $workflow,
        private readonly MailService $mail,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
        private readonly EmailTemplateService $templates
    ) {
    }

    /** @return array{active_count:int,balance_due_total:float,pending_count:int} */
    public function listSummaryMetrics(): array
    {
        return [
            'active_count' => (int) ($this->db->fetch("SELECT COUNT(*) c FROM members WHERE status = 'active'")['c'] ?? 0),
            'balance_due_total' => (float) ($this->db->fetch(
                'SELECT COALESCE(SUM(balance_due), 0) c FROM members WHERE balance_due > 0'
            )['c'] ?? 0),
            'pending_count' => (int) ($this->db->fetch("SELECT COUNT(*) c FROM members WHERE status = 'pending'")['c'] ?? 0),
        ];
    }

    /** @return array{ok:bool,error?:string,sent?:bool} */
    public function sendPaymentReminder(int $memberId, string $ip): array
    {
        $member = $this->members->find($memberId);
        if (!$member) {
            return ['ok' => false, 'error' => __('validation.required')];
        }
        if ((float) ($member['balance_due'] ?? 0) <= 0) {
            return ['ok' => false, 'error' => __('members.remind_no_balance')];
        }
        $email = strtolower(trim((string) ($member['fields']['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => __('members.remind_no_email')];
        }
        $name = trim(($member['fields']['first_name'] ?? '') . ' ' . ($member['fields']['last_name'] ?? ''));
        $vars = [
            'email' => $email,
            'member_email' => $email,
            'member_name' => $name !== '' ? $name : $member['member_number'],
            'payment_amount' => number_format((float) $member['balance_due'], 2, ',', '.') . ' €',
            'membership_period' => (string) ($member['period_label'] ?? ''),
            'if_membership_period' => trim((string) ($member['period_label'] ?? '')) !== '' ? '1' : '',
            'association_name' => (string) $this->settings->get('association.name', ''),
        ];
        $lang = (string) (auth_user()['locale'] ?? config('app.locale', 'it'));
        $sent = $this->workflow->dispatch('member.payment_reminder', $vars, $lang, $ip);
        if ($sent === 0) {
            if (!$this->mail->isReady()) {
                return ['ok' => false, 'error' => __('mail.required_for_reminder')];
            }
            $tpl = $this->templates->findBySlug('payment-reminder');
            if ($tpl) {
                $rendered = $this->templates->renderTemplate($tpl, $lang, $vars);
                $html = ($rendered['body_format'] ?? 'text') === 'html';
                $body = $rendered['body'];
                $plain = $html ? trim(strip_tags(preg_replace('/<\s*br\s*\/?>/i', "\n", $body) ?? $body)) : $body;
                $result = $this->mail->send($email, $rendered['subject'], $plain, $html ? $body : null);
                if (empty($result['ok'])) {
                    return ['ok' => false, 'error' => (string) ($result['error'] ?? __('mail.send_failed'))];
                }
            } else {
                $result = $this->mail->send(
                    $email,
                    __('members.payment_reminder_subject', ['association' => $vars['association_name']]),
                    __('members.payment_reminder_body', [
                        'name' => $vars['member_name'],
                        'amount' => $vars['payment_amount'],
                        'association' => $vars['association_name'],
                    ])
                );
                if (empty($result['ok'])) {
                    return ['ok' => false, 'error' => (string) ($result['error'] ?? __('mail.send_failed'))];
                }
            }
        }
        $this->audit->log('member.payment_reminder_sent', 'member', (string) $memberId, null, ['email' => $email], $ip);
        return ['ok' => true, 'sent' => true];
    }

    /**
     * @param list<int|string> $memberIds
     * @param array<string,mixed> $payload
     * @return array{ok:bool,errors?:array<string,string>,processed?:int,skipped?:int}
     */
    public function bulk(string $action, array $memberIds, array $payload, string $ip): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return ['ok' => false, 'errors' => ['members' => __('members.bulk_none_selected')]];
        }

        return match ($action) {
            'payment_reminder' => $this->bulkPaymentReminder($ids, $ip),
            'group_email' => $this->bulkGroupEmail($ids, $payload, $ip),
            'mass_renewal' => $this->bulkMassRenewal($ids, $ip),
            default => ['ok' => false, 'errors' => ['action' => __('validation.in')]],
        };
    }

    /** @param list<int> $ids */
    private function bulkPaymentReminder(array $ids, string $ip): array
    {
        $processed = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $result = $this->sendPaymentReminder($id, $ip);
            if (!empty($result['ok'])) {
                $processed++;
            } else {
                $skipped++;
            }
        }
        if ($processed === 0) {
            return ['ok' => false, 'errors' => ['members' => __('members.bulk_remind_failed')], 'processed' => 0, 'skipped' => $skipped];
        }
        return ['ok' => true, 'processed' => $processed, 'skipped' => $skipped];
    }

    /**
     * @param list<int> $ids
     * @param array<string,mixed> $payload
     */
    private function bulkGroupEmail(array $ids, array $payload, string $ip): array
    {
        if (!$this->mail->isReady()) {
            return ['ok' => false, 'errors' => ['mail' => __('mail.required_for_group')]];
        }
        $subject = trim((string) ($payload['subject'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        if ($subject === '' || $body === '') {
            return ['ok' => false, 'errors' => ['subject' => __('validation.required')]];
        }
        $processed = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $member = $this->members->find($id);
            if (!$member) {
                $skipped++;
                continue;
            }
            $email = strtolower(trim((string) ($member['fields']['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }
            $name = trim(($member['fields']['first_name'] ?? '') . ' ' . ($member['fields']['last_name'] ?? ''));
            $personalBody = str_replace(
                ['{{member_name}}', '{{member_number}}'],
                [$name !== '' ? $name : $member['member_number'], (string) $member['member_number']],
                $body
            );
            $result = $this->mail->send($email, $subject, $personalBody);
            if (!empty($result['ok'])) {
                $processed++;
                $this->audit->log('member.group_email_sent', 'member', (string) $id, null, ['email' => $email], $ip);
            } else {
                $skipped++;
            }
        }
        if ($processed === 0) {
            return ['ok' => false, 'errors' => ['members' => __('members.bulk_email_failed')], 'processed' => 0, 'skipped' => $skipped];
        }
        return ['ok' => true, 'processed' => $processed, 'skipped' => $skipped];
    }

    /** @param list<int> $ids */
    private function bulkMassRenewal(array $ids, string $ip): array
    {
        $period = $this->db->fetch('SELECT id FROM membership_periods WHERE is_current = 1 ORDER BY id DESC LIMIT 1');
        if (!$period) {
            return ['ok' => false, 'errors' => ['period' => __('members.bulk_renew_no_period')]];
        }
        $periodId = (int) $period['id'];
        $processed = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $member = $this->db->fetch(
                'SELECT m.*, mt.price FROM members m INNER JOIN member_types mt ON mt.id = m.member_type_id WHERE m.id = :id',
                ['id' => $id]
            );
            if (!$member || ($member['status'] ?? '') !== 'active') {
                $skipped++;
                continue;
            }
            $price = round((float) ($member['price'] ?? 0), 2);
            $this->db->update('members', [
                'membership_period_id' => $periodId,
                'balance_due' => $price,
            ], 'id = :id', ['id' => $id]);
            $this->audit->log('member.mass_renewed', 'member', (string) $id, null, [
                'period_id' => $periodId,
                'balance_due' => $price,
            ], $ip);
            $processed++;
        }
        if ($processed === 0) {
            return ['ok' => false, 'errors' => ['members' => __('members.bulk_renew_failed')], 'processed' => 0, 'skipped' => $skipped];
        }
        return ['ok' => true, 'processed' => $processed, 'skipped' => $skipped];
    }
}
