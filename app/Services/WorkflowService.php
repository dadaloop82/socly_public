<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Core\Validator;

final class WorkflowService
{
    public function __construct(
        private readonly Database $db,
        private readonly Validator $validator,
        private readonly EmailTemplateService $templates,
        private readonly MailService $mail,
        private readonly AuditService $audit
    ) {
    }

    /** @return array<string, array<string, string>> */
    public static function eventGroups(): array
    {
        return [
            'workflow.group_users' => [
                'user.created' => 'workflow.event_user_created',
            ],
            'workflow.group_enrollment' => [
                'member.enrollment_otp' => 'workflow.event_enrollment_otp',
            ],
            'workflow.group_payments' => [
                'member.payment_reminder' => 'workflow.event_payment_reminder',
                'member.payment_received' => 'workflow.event_payment_received',
            ],
        ];
    }

    /** @return array<string, string> */
    public static function events(): array
    {
        $flat = [];
        foreach (self::eventGroups() as $events) {
            foreach ($events as $key => $labelKey) {
                $flat[$key] = $labelKey;
            }
        }
        return $flat;
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM workflow_rules ORDER BY event_key ASC, id ASC');
    }

    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM workflow_rules WHERE id = :id', ['id' => $id]);
    }

    /** @return array{ok:bool,errors?:array<string,string>,id?:int} */
    public function save(array $data): array
    {
        $id = (int) ($data['id'] ?? 0);
        $eventKey = (string) ($data['event_key'] ?? '');
        $templateSlug = trim((string) ($data['template_slug'] ?? ''));
        if (!isset(self::events()[$eventKey])) {
            return ['ok' => false, 'errors' => ['event_key' => __('validation.required')]];
        }
        if (!$this->validator->validate($data, [
            'name' => 'required|string|max:160',
            'template_slug' => 'required|string|max:80',
        ])) {
            return ['ok' => false, 'errors' => $this->validator->firstErrors()];
        }
        if (!$this->templates->findBySlug($templateSlug)) {
            return ['ok' => false, 'errors' => ['template_slug' => __('workflow.template_missing')]];
        }
        $payload = [
            'name' => trim((string) $data['name']),
            'event_key' => $eventKey,
            'template_slug' => $templateSlug,
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'delay_minutes' => max(0, min(10080, (int) ($data['delay_minutes'] ?? 0))),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($id > 0) {
            $this->db->update('workflow_rules', $payload, 'id = :id', ['id' => $id]);
            return ['ok' => true, 'id' => $id];
        }
        $payload['created_at'] = date('Y-m-d H:i:s');
        return ['ok' => true, 'id' => $this->db->insert('workflow_rules', $payload)];
    }

    /** @return array{ok:bool,error?:string} */
    public function delete(int $id): array
    {
        if (!$this->find($id)) {
            return ['ok' => false, 'error' => __('validation.required')];
        }
        $this->db->query('DELETE FROM workflow_rules WHERE id = :id', ['id' => $id]);
        return ['ok' => true];
    }

    public function toggle(int $id): bool
    {
        $rule = $this->find($id);
        if (!$rule) {
            return false;
        }
        $enabled = empty($rule['enabled']) ? 1 : 0;
        $this->db->update('workflow_rules', [
            'enabled' => $enabled,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);
        return true;
    }

    /**
     * @param array<string, string> $vars Must include recipient email as `email` or `_to`
     */
    public function dispatch(string $eventKey, array $vars, ?string $lang = null, ?string $ip = null): int
    {
        if (!isset(self::events()[$eventKey]) || !$this->mail->isReady()) {
            return 0;
        }
        $rules = $this->db->fetchAll(
            'SELECT * FROM workflow_rules WHERE enabled = 1 AND event_key = :e ORDER BY id ASC',
            ['e' => $eventKey]
        );
        if ($rules === []) {
            return 0;
        }
        if ($eventKey === 'member.enrollment_otp') {
            $rules = [$rules[0]];
        }
        $sent = 0;
        foreach ($rules as $rule) {
            if ($this->sendRule($rule, $vars, $lang, $ip)) {
                $sent++;
            }
        }
        return $sent;
    }

    /** @param array<string,mixed> $rule @param array<string,string> $vars */
    private function sendRule(array $rule, array $vars, ?string $lang, ?string $ip): bool
    {
        $to = trim((string) ($vars['email'] ?? $vars['_to'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $slug = (string) ($rule['template_slug'] ?? '');
        $tpl = $this->templates->findBySlug($slug);
        if (!$tpl) {
            return false;
        }
        $lang = $this->templates->normalizeLang($lang ?? ($vars['lang'] ?? 'it'));
        $rendered = $this->templates->renderTemplate($tpl, $lang, $vars);
        $html = ($rendered['body_format'] ?? 'text') === 'html';
        $body = $rendered['body'];
        $plain = $html ? trim(strip_tags(preg_replace('/<\s*br\s*\/?>/i', "\n", $body) ?? $body)) : $body;
        $result = $this->mail->send($to, $rendered['subject'], $plain, $html ? $body : null);
        if (!empty($result['ok'])) {
            $this->audit->log('workflow.sent', 'workflow', (string) ($rule['id'] ?? ''), null, [
                'event' => (string) ($rule['event_key'] ?? ''),
                'template' => $slug,
                'to' => $to,
            ], $ip ?? '');
            return true;
        }
        return false;
    }
}
