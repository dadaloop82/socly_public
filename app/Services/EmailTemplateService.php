<?php

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
        private readonly SettingsService $settings
    ) {
    }

    public function ensureDefaults(): void
    {
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

    /** @return array<string, string> */
    public static function placeholderCatalog(): array
    {
        return [
            'association_name' => 'email_templates.ph_association_name',
            'association_email' => 'email_templates.ph_association_email',
            'member_name' => 'email_templates.ph_member_name',
            'member_email' => 'email_templates.ph_member_email',
            'member_card_number' => 'email_templates.ph_member_card_number',
            'operator_email' => 'email_templates.ph_operator_email',
            'operator_password' => 'email_templates.ph_operator_password',
            'login_url' => 'email_templates.ph_login_url',
            'otp_code' => 'email_templates.ph_otp_code',
            'payment_amount' => 'email_templates.ph_payment_amount',
            'membership_period' => 'email_templates.ph_membership_period',
            'payment_due_date' => 'email_templates.ph_payment_due_date',
        ];
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
                return ['ok' => true, 'id' => $id];
            }
            $payload['created_at'] = date('Y-m-d H:i:s');
            $newId = $this->db->insert('email_templates', $payload);
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
