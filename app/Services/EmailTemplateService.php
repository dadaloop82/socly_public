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
use Socly\Core\Validator;

final class EmailTemplateService
{
    /** @var list<string> */
    public const SYSTEM_SLUGS = ['user-welcome', 'enrollment-otp', 'payment-reminder'];

    public function __construct(
        private readonly Database $db,
        private readonly Validator $validator,
        private readonly SettingsService $settings,
        private readonly AuditService $audit
    ) {
    }

    public function ensureDefaults(): void
    {
        $this->ensureTables();
        $count = (int) ($this->db->fetch('SELECT COUNT(*) AS c FROM email_templates')['c'] ?? 0);
        if ($count > 0) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $defaults = [
            [
                'slug' => 'user-welcome',
                'name' => 'Benvenuto operatore',
                'is_system' => 1,
                'subject' => 'Accesso a SOCLY — credenziali operatore',
                'body' => "Ciao,\n\nÈ stato creato il tuo accesso al pannello SOCLY per {{association_name}}.\n\nURL: {{login_url}}\nEmail: {{operator_email}}\nPassword: {{operator_password}}\n\nTi consigliamo di cambiare la password al primo accesso.\n",
                'subject_en' => 'SOCLY access — operator credentials',
                'body_en' => "Hello,\n\nYour SOCLY panel access for {{association_name}} has been created.\n\nURL: {{login_url}}\nEmail: {{operator_email}}\nPassword: {{operator_password}}\n\nWe recommend changing your password on first login.\n",
                'subject_de' => 'SOCLY-Zugang — Operatoren-Zugangsdaten',
                'body_de' => "Hallo,\n\nIhr SOCLY-Panel-Zugang für {{association_name}} wurde erstellt.\n\nURL: {{login_url}}\nE-Mail: {{operator_email}}\nPasswort: {{operator_password}}\n\nWir empfehlen, das Passwort beim ersten Login zu ändern.\n",
            ],
            [
                'slug' => 'enrollment-otp',
                'name' => 'OTP validazione iscrizione',
                'is_system' => 1,
                'subject' => 'Codice di conferma iscrizione — {{association_name}}',
                'body' => "Ciao {{member_name}},\n\nPer completare l'iscrizione a {{association_name}} inserisci questo codice OTP:\n\n{{otp_code}}\n\nIl codice scade tra 15 minuti.\n",
                'subject_en' => 'Enrollment confirmation code — {{association_name}}',
                'body_en' => "Hi {{member_name}},\n\nTo complete enrollment at {{association_name}} enter this OTP code:\n\n{{otp_code}}\n\nThe code expires in 15 minutes.\n",
                'subject_de' => 'Bestätigungscode Anmeldung — {{association_name}}',
                'body_de' => "Hallo {{member_name}},\n\nZum Abschluss der Anmeldung bei {{association_name}} geben Sie diesen OTP-Code ein:\n\n{{otp_code}}\n\nDer Code läuft in 15 Minuten ab.\n",
            ],
            [
                'slug' => 'payment-reminder',
                'name' => 'Sollecito quota',
                'is_system' => 1,
                'subject' => 'Promemoria quota associativa — {{association_name}}',
                'body' => "Ciao {{member_name}},\n\nTi ricordiamo che la quota associativa{{#if_membership_period}} per {{membership_period}}{{/if_membership_period}} risulta da saldare.\n\nImporto: {{payment_amount}}\n\nPuoi contattare l'associazione per modalità di pagamento.\n\nCordiali saluti,\n{{association_name}}\n",
                'subject_en' => 'Membership fee reminder — {{association_name}}',
                'body_en' => "Hi {{member_name}},\n\nThis is a reminder that your membership fee{{#if_membership_period}} for {{membership_period}}{{/if_membership_period}} is outstanding.\n\nAmount: {{payment_amount}}\n\nPlease contact the association for payment options.\n\nKind regards,\n{{association_name}}\n",
                'subject_de' => 'Erinnerung Mitgliedsbeitrag — {{association_name}}',
                'body_de' => "Hallo {{member_name}},\n\nwir erinnern Sie daran, dass der Mitgliedsbeitrag{{#if_membership_period}} für {{membership_period}}{{/if_membership_period}} noch offen ist.\n\nBetrag: {{payment_amount}}\n\nBitte wenden Sie sich an den Verein für Zahlungsmodalitäten.\n\nFreundliche Grüße,\n{{association_name}}\n",
            ],
        ];
        foreach ($defaults as $row) {
            $this->db->insert('email_templates', [
                'slug' => $row['slug'],
                'name' => $row['name'],
                'subject' => $row['subject'],
                'body' => $row['body'],
                'subject_en' => $row['subject_en'],
                'body_en' => $row['body_en'],
                'subject_de' => $row['subject_de'],
                'body_de' => $row['body_de'],
                'body_format' => 'text',
                'is_system' => $row['is_system'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $wfCount = (int) ($this->db->fetch('SELECT COUNT(*) AS c FROM workflow_rules')['c'] ?? 0);
        if ($wfCount === 0) {
            $rules = [
                ['Benvenuto operatore', 'user.created', 'user-welcome'],
                ['OTP iscrizione socio', 'member.enrollment_otp', 'enrollment-otp'],
                ['Sollecito quota', 'member.payment_reminder', 'payment-reminder'],
            ];
            foreach ($rules as [$name, $event, $slug]) {
                $this->db->insert('workflow_rules', [
                    'name' => $name,
                    'event_key' => $event,
                    'template_slug' => $slug,
                    'enabled' => 1,
                    'delay_minutes' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function ensureTables(): void
    {
        try {
            $this->db->fetch('SELECT 1 FROM email_templates LIMIT 1');
            return;
        } catch (\Throwable) {
        }
        $path = dirname(__DIR__, 2) . '/database/migrations/018_email_templates_workflow.sql';
        if (!is_file($path)) {
            return;
        }
        $sql = file_get_contents($path);
        if ($sql === false) {
            return;
        }
        $parts = array_filter(array_map('trim', explode(';', preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql)));
        foreach ($parts as $statement) {
            if ($statement !== '') {
                $this->db->pdo()->exec($statement);
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM email_templates ORDER BY name ASC');
    }

    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM email_templates WHERE id = :id', ['id' => $id]);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetch('SELECT * FROM email_templates WHERE slug = :s', ['s' => $slug]);
    }

    /**
     * Every placeholder the workflow can fill, grouped by the area that produces it.
     *
     * The runtime map is built in `WorkflowService::auditVars()`: a key that an event does not carry
     * renders as an empty string, so listing a whole section is safe. Add-on keys are discovered
     * separately (`WorkflowService::addonPlaceholders()`).
     *
     * @return array<string, array<string, string>> group label key => [placeholder => label key]
     */
    public static function placeholderGroups(): array
    {
        return [
            'email_templates.ph_group_association' => [
                'association_name' => 'email_templates.ph_association_name',
                'association_email' => 'email_templates.ph_association_email',
                'login_url' => 'email_templates.ph_login_url',
                'lang' => 'email_templates.ph_lang',
                'ip' => 'email_templates.ph_ip',
            ],
            'email_templates.ph_group_event' => [
                'event' => 'email_templates.ph_event',
                'event_label' => 'email_templates.ph_event_label',
                'entity_type' => 'email_templates.ph_entity_type',
                'entity_id' => 'email_templates.ph_entity_id',
                'entity_title' => 'email_templates.ph_entity_title',
                'entity_url' => 'email_templates.ph_entity_url',
                'entity_date' => 'email_templates.ph_entity_date',
                'entity_amount' => 'email_templates.ph_entity_amount',
                'entity_status' => 'email_templates.ph_entity_status',
            ],
            'email_templates.ph_group_people' => [
                'member_name' => 'email_templates.ph_member_name',
                'member_email' => 'email_templates.ph_member_email',
                'member_id' => 'email_templates.ph_member_id',
                'member_number' => 'email_templates.ph_member_number',
                'member_status' => 'email_templates.ph_member_status',
                'member_card_number' => 'email_templates.ph_member_card_number',
                'person_name' => 'email_templates.ph_person_name',
                'person_email' => 'email_templates.ph_person_email',
                'user_name' => 'email_templates.ph_user_name',
                'user_email' => 'email_templates.ph_user_email',
                'operator_name' => 'email_templates.ph_operator_name',
                'operator_email' => 'email_templates.ph_operator_email',
                'operator_password' => 'email_templates.ph_operator_password',
                'otp_code' => 'email_templates.ph_otp_code',
                'enrollment_method' => 'email_templates.ph_enrollment_method',
            ],
            'email_templates.ph_group_payments' => [
                'payment_amount' => 'email_templates.ph_payment_amount',
                'payment_method' => 'email_templates.ph_payment_method',
                'payment_date' => 'email_templates.ph_payment_date',
                'payment_type' => 'email_templates.ph_payment_type',
                'membership_period' => 'email_templates.ph_membership_period',
                'payment_due_date' => 'email_templates.ph_payment_due_date',
            ],
            'email_templates.ph_group_documents' => [
                'document_title' => 'email_templates.ph_document_title',
                'document_number' => 'email_templates.ph_document_number',
                'document_category' => 'email_templates.ph_document_category',
                'document_date' => 'email_templates.ph_document_date',
            ],
            'email_templates.ph_group_deadlines' => [
                'deadline_title' => 'email_templates.ph_deadline_title',
                'deadline_due_date' => 'email_templates.ph_deadline_due_date',
                'deadline_category' => 'email_templates.ph_deadline_category',
                'deadline_status' => 'email_templates.ph_deadline_status',
            ],
            'email_templates.ph_group_treasury' => [
                'treasury_description' => 'email_templates.ph_treasury_description',
                'treasury_amount' => 'email_templates.ph_treasury_amount',
                'treasury_date' => 'email_templates.ph_treasury_date',
                'treasury_type' => 'email_templates.ph_treasury_type',
                'treasury_account' => 'email_templates.ph_treasury_account',
                'treasury_status' => 'email_templates.ph_treasury_status',
            ],
            'email_templates.ph_group_budgets' => [
                'budget_title' => 'email_templates.ph_budget_title',
                'budget_year' => 'email_templates.ph_budget_year',
                'budget_status' => 'email_templates.ph_budget_status',
            ],
            'email_templates.ph_group_organs' => [
                'org_role_label' => 'email_templates.ph_org_role_label',
                'org_role_key' => 'email_templates.ph_org_role_key',
            ],
            'email_templates.ph_group_import' => [
                'import_total' => 'email_templates.ph_import_total',
                'import_created' => 'email_templates.ph_import_created',
                'import_updated' => 'email_templates.ph_import_updated',
                'import_skipped' => 'email_templates.ph_import_skipped',
                'import_failed' => 'email_templates.ph_import_failed',
            ],
        ];
    }

    /** @return array<string, string> every placeholder, flattened (placeholder => label key) */
    public static function placeholderCatalog(): array
    {
        $out = [];
        foreach (self::placeholderGroups() as $group) {
            foreach ($group as $key => $labelKey) {
                $out[$key] = $labelKey;
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    public static function conditionalCatalog(): array
    {
        return [
            'if_membership_period' => 'email_templates.cond_if_membership_period',
            'if_payment_due_date' => 'email_templates.cond_if_payment_due_date',
            'if_member_email' => 'email_templates.cond_if_member_email',
        ];
    }

    /** @return array<string, array<string, string>> */
    public function sampleVarsByLang(): array
    {
        $out = [];
        foreach (['it', 'en', 'de'] as $lang) {
            $out[$lang] = $this->sampleVars($lang);
        }
        return $out;
    }

    /** @return array<string, string> */
    public function sampleVars(string $lang = 'it'): array
    {
        $lang = $this->normalizeLang($lang);
        $base = [
            'association_name' => (string) $this->settings->get('association.name', 'ASD Esempio'),
            'association_email' => (string) $this->settings->get('association.email', 'info@esempio.it'),
            'member_name' => 'Mario Rossi',
            'member_email' => 'mario.rossi@esempio.it',
            'member_card_number' => '2025-0042',
            'operator_email' => 'operatore@esempio.it',
            'operator_password' => 'DemoPass123!',
            'login_url' => url('/login'),
            'otp_code' => '847291',
            'payment_amount' => '€ 25,00',
            'membership_period' => '2025/2026',
            'payment_due_date' => date('d/m/Y', strtotime('+14 days')),
            'if_membership_period' => '1',
            'if_payment_due_date' => '1',
            'if_member_email' => '1',
            // Generic variables shared by every Workflow event (members, documents, deadlines,
            // treasury, budgets, bodies and plugins).
            'event' => 'member.created',
            'event_label' => (string) __('workflow.event_member_created'),
            'entity_type' => 'member',
            'entity_id' => '42',
            'entity_title' => 'Mario Rossi',
            'entity_url' => url('/members/42'),
            'entity_date' => date('d/m/Y'),
            'entity_amount' => '€ 25,00',
            'entity_status' => 'active',
            'lang' => 'it',
            'ip' => '192.0.2.10',
            'operator_name' => 'Anna Bianchi',
            'member_id' => '42',
            'member_number' => '2025-0042',
            'member_status' => 'active',
            'person_name' => 'Mario Rossi',
            'person_email' => 'mario.rossi@esempio.it',
            'user_name' => 'Anna Bianchi',
            'user_email' => 'anna.bianchi@esempio.it',
            'enrollment_method' => 'online',
            'payment_method' => 'Bonifico',
            'payment_date' => date('d/m/Y'),
            'payment_type' => 'membership_fee',
            'document_title' => 'Verbale assemblea',
            'document_number' => '2026/0012',
            'document_category' => 'minutes',
            'document_date' => date('d/m/Y'),
            'deadline_title' => 'Invio modello EAS',
            'deadline_due_date' => date('d/m/Y', strtotime('+30 days')),
            'deadline_category' => 'tax',
            'deadline_status' => 'open',
            'treasury_amount' => '€ 1.250,00',
            'treasury_description' => 'Quota associativa',
            'treasury_date' => date('d/m/Y'),
            'treasury_type' => 'income',
            'treasury_account' => 'bank',
            'treasury_status' => 'confirmed',
            'budget_title' => 'Quote associative',
            'budget_year' => (string) (int) date('Y'),
            'budget_status' => 'approved',
            'org_role_label' => 'Presidente',
            'org_role_key' => 'president',
            'import_total' => '120',
            'import_created' => '98',
            'import_updated' => '15',
            'import_skipped' => '5',
            'import_failed' => '2',
        ];
        if ($lang === 'en') {
            $base['member_name'] = 'John Smith';
            $base['member_email'] = 'john.smith@example.org';
            $base['payment_amount'] = '€ 25.00';
        } elseif ($lang === 'de') {
            $base['member_name'] = 'Hans Müller';
            $base['member_email'] = 'hans.mueller@beispiel.de';
        }
        return $base;
    }

    /** @param array<string, mixed> $tpl */
    public function localized(array $tpl, ?string $lang): array
    {
        $lang = $this->normalizeLang($lang);
        $subjectIt = (string) ($tpl['subject'] ?? '');
        $bodyIt = (string) ($tpl['body'] ?? '');
        $fallback = false;
        if ($lang === 'en') {
            $subject = trim((string) ($tpl['subject_en'] ?? ''));
            $body = trim((string) ($tpl['body_en'] ?? ''));
        } elseif ($lang === 'de') {
            $subject = trim((string) ($tpl['subject_de'] ?? ''));
            $body = trim((string) ($tpl['body_de'] ?? ''));
        } else {
            $subject = $subjectIt;
            $body = $bodyIt;
        }
        if ($lang !== 'it') {
            if ($subject === '') {
                $subject = $subjectIt;
                $fallback = true;
            }
            if ($body === '') {
                $body = $bodyIt;
                $fallback = true;
            }
        }
        return [
            'subject' => $subject,
            'body' => $body,
            'lang' => $lang,
            'fallback' => $fallback,
            'body_format' => $this->normalizeBodyFormat((string) ($tpl['body_format'] ?? 'text')),
        ];
    }

    /** @param array<string, string> $vars */
    public function render(string $template, array $vars): string
    {
        $positive = '/\{\{#\s*([a-z0-9_]+)\s*\}\}(.*?)\{\{\/\s*\1\s*\}\}/is';
        $negative = '/\{\{\^\s*([a-z0-9_]+)\s*\}\}(.*?)\{\{\/\s*\1\s*\}\}/is';
        for ($i = 0; $i < 12; $i++) {
            $prev = $template;
            $template = (string) preg_replace_callback(
                $positive,
                static function (array $m) use ($vars): string {
                    return self::flagTruthy($vars[strtolower($m[1])] ?? '') ? $m[2] : '';
                },
                $template
            );
            $template = (string) preg_replace_callback(
                $negative,
                static function (array $m) use ($vars): string {
                    return self::flagTruthy($vars[strtolower($m[1])] ?? '') ? '' : $m[2];
                },
                $template
            );
            if ($template === $prev) {
                break;
            }
        }
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            static function (array $m) use ($vars): string {
                return $vars[strtolower($m[1])] ?? '';
            },
            $template
        );
    }

    /** @param array<string, string> $vars */
    public function renderTemplate(array $tpl, ?string $lang, array $vars): array
    {
        $localized = $this->localized($tpl, $lang);
        return [
            'subject' => $this->render($localized['subject'], $vars),
            'body' => $this->render($localized['body'], $vars),
            'body_format' => $localized['body_format'],
            'lang' => $localized['lang'],
        ];
    }

    /** @return array{ok:bool,errors?:array<string,string>,id?:int} */
    public function save(array $data): array
    {
        $id = (int) ($data['id'] ?? 0);
        $slug = strtolower(trim((string) ($data['slug'] ?? '')));
        $slug = trim((string) (preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?? ''), '-');
        if (!$this->validator->validate(array_merge($data, ['slug' => $slug]), [
            'name' => 'required|string|max:120',
            'slug' => 'required|string|max:80',
            'subject' => 'required|string|max:250',
            'body' => 'required|string',
            'subject_en' => 'nullable|string|max:250',
            'body_en' => 'nullable|string',
            'subject_de' => 'nullable|string|max:250',
            'body_de' => 'nullable|string',
            'body_format' => 'required|in:text,html',
        ])) {
            return ['ok' => false, 'errors' => $this->validator->firstErrors()];
        }
        $existing = $id > 0 ? $this->find($id) : null;
        if ($existing && !empty($existing['is_system'])) {
            $slug = (string) $existing['slug'];
        }
        $dup = $this->db->fetch(
            'SELECT id FROM email_templates WHERE slug = :s AND id <> :id',
            ['s' => $slug, 'id' => $id]
        );
        if ($dup) {
            return ['ok' => false, 'errors' => ['slug' => __('validation.unique')]];
        }
        $payload = [
            'name' => trim((string) $data['name']),
            'slug' => $slug,
            'subject' => trim((string) $data['subject']),
            'body' => trim((string) $data['body']),
            'subject_en' => trim((string) ($data['subject_en'] ?? '')),
            'body_en' => trim((string) ($data['body_en'] ?? '')),
            'subject_de' => trim((string) ($data['subject_de'] ?? '')),
            'body_de' => trim((string) ($data['body_de'] ?? '')),
            'body_format' => $this->normalizeBodyFormat((string) ($data['body_format'] ?? 'text')),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        try {
            if ($id > 0) {
                $this->db->update('email_templates', $payload, 'id = :id', ['id' => $id]);
                $this->audit->log('email_template.updated', 'email_template', (string) $id, $existing, $payload);
                return ['ok' => true, 'id' => $id];
            }
            $payload['created_at'] = date('Y-m-d H:i:s');
            $newId = $this->db->insert('email_templates', $payload);
            $this->audit->log('email_template.created', 'email_template', (string) $newId, null, $payload);
            return ['ok' => true, 'id' => $newId];
        } catch (\Throwable) {
            return ['ok' => false, 'errors' => ['slug' => __('email_templates.save_failed')]];
        }
    }

    /** @return array{ok:bool,error?:string} */
    public function delete(int $id): array
    {
        $tpl = $this->find($id);
        if (!$tpl) {
            return ['ok' => false, 'error' => __('validation.required')];
        }
        if (!empty($tpl['is_system']) || in_array((string) ($tpl['slug'] ?? ''), self::SYSTEM_SLUGS, true)) {
            return ['ok' => false, 'error' => __('email_templates.cannot_delete_system')];
        }
        $used = $this->db->fetch(
            'SELECT id FROM workflow_rules WHERE template_slug = :s LIMIT 1',
            ['s' => (string) $tpl['slug']]
        );
        if ($used) {
            return ['ok' => false, 'error' => __('email_templates.cannot_delete_in_use')];
        }
        $this->db->query('DELETE FROM email_templates WHERE id = :id', ['id' => $id]);
        $this->audit->log('email_template.deleted', 'email_template', (string) $id, $tpl, null);
        return ['ok' => true];
    }

    public function normalizeLang(?string $lang): string
    {
        $lang = strtolower(trim((string) $lang));
        return in_array($lang, ['it', 'en', 'de'], true) ? $lang : 'it';
    }

    public function normalizeBodyFormat(?string $format): string
    {
        return strtolower(trim((string) $format)) === 'html' ? 'html' : 'text';
    }

    private static function flagTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        $v = trim((string) $value);
        if ($v === '' || $v === '0') {
            return false;
        }
        return !in_array(strtolower($v), ['no', 'nein', 'false', 'off', 'n'], true);
    }
}
