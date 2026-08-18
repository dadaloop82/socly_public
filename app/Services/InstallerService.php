<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Core\Encryptor;
use Socly\Core\Migrator;
use Socly\Support\MemberFieldTypes;
use Socly\Support\Permission;

final class InstallerService
{
    public function __construct(
        private readonly Database $db,
        private readonly Migrator $migrator,
        private readonly SettingsService $settings,
        private readonly AuditService $audit
    ) {
    }

    public static function writeEnv(array $data): void
    {
        $lines = [
            'APP_NAME=SOCLY',
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_URL=' . ($data['app_url'] ?? 'http://localhost'),
            'APP_KEY=' . ($data['app_key'] ?? Encryptor::generateKey()),
            '',
            'DB_HOST=' . ($data['db_host'] ?? '127.0.0.1'),
            'DB_PORT=' . ($data['db_port'] ?? '3306'),
            'DB_DATABASE=' . ($data['db_database'] ?? 'socly'),
            'DB_USERNAME=' . ($data['db_username'] ?? 'socly'),
            'DB_PASSWORD=' . ($data['db_password'] ?? ''),
            '',
            'APP_LOCALE=' . ($data['app_locale'] ?? 'it'),
            'SESSION_LIFETIME=120',
            '',
            'UPDATE_NOTIFY=true',
            'UPDATE_MANIFEST_URL=https://raw.githubusercontent.com/dadaloop82/socly_public/main/latest.json',
            'UPDATE_REPO=git@github.com-socly:dadaloop82/socly.git',
            'UPDATE_CHANNEL=main',
            'UPDATE_ENABLED=' . ((isset($data['update_enabled']) ? !empty($data['update_enabled']) : false) ? 'true' : 'false'),
            '',
        ];
        file_put_contents(base_path('.env'), implode("\n", $lines));
    }

    public function runMigrations(): void
    {
        $this->migrator->migrate();
    }

    public function seedPermissions(): void
    {
        foreach (Permission::catalogue() as $key => $description) {
            $exists = $this->db->fetch('SELECT id FROM permissions WHERE `key` = :k', ['k' => $key]);
            if (!$exists) {
                $this->db->insert('permissions', ['key' => $key, 'description' => $description]);
            }
        }
    }

    /** Platform SuperAdmin — not the association Admin created at the end of setup. */
    public function createSuperAdmin(string $name, string $email, string $password, string $locale = 'it'): int
    {
        return $this->db->insert('users', [
            'email' => strtolower(trim($email)),
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'name' => $name,
            'locale' => $locale,
            'is_system_admin' => 1,
            'is_active' => 1,
        ]);
    }

    /** @deprecated Use createSuperAdmin() */
    public function createAdmin(string $name, string $email, string $password): int
    {
        return $this->createSuperAdmin($name, $email, $password);
    }

    public function saveAssociation(array $data): void
    {
        $this->settings->set('association.name', $data['name']);
        $this->settings->set('association.email', $data['email'] ?? '');
        $this->settings->set('association.phone', $data['phone'] ?? '');
        $this->settings->set('association.address', $data['address'] ?? '');
        $this->settings->set('branding.primary', $data['primary'] ?? '#0D6E66');
        $this->settings->set('branding.accent', $data['accent'] ?? '#B84A1B');
        $this->settings->set('app.locale', $data['locale'] ?? 'it');
        $this->settings->set('app.currency', 'EUR');
        $this->settings->set('gdpr.enabled', !empty($data['gdpr_enabled']) ? '1' : '0');
        $this->settings->set('app.installed', '1');
    }

    public function createPeriod(string $label, string $starts, string $ends): int
    {
        $this->db->query('UPDATE membership_periods SET is_current = 0');
        return $this->db->insert('membership_periods', [
            'label' => $label,
            'starts_on' => $starts,
            'ends_on' => $ends,
            'is_current' => 1,
        ]);
    }

    public function createMemberType(array $names, float $price, int $sort = 0): int
    {
        return $this->db->insert('member_types', [
            'name_json' => json_encode($names, JSON_UNESCAPED_UNICODE),
            'price' => $price,
            'is_active' => 1,
            'sort_order' => $sort,
        ]);
    }

    /** @param list<array<string,mixed>> $fields */
    public function seedFields(array $fields): void
    {
        foreach ($fields as $i => $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $exists = $this->db->fetch('SELECT id, form_step FROM member_field_definitions WHERE `key` = :k', ['k' => $key]);
            if ($exists) {
                // Never overwrite user config (enabled/required/order/labels). Only fill gaps + keep type semantics.
                $patch = [];
                if (isset($field['field_type']) && (string) $field['field_type'] !== '') {
                    $patch['field_type'] = (string) $field['field_type'];
                }
                $formStep = trim((string) ($exists['form_step'] ?? ''));
                if ($formStep === '') {
                    $patch['form_step'] = (string) ($field['form_step'] ?? (
                        (($field['field_type'] ?? '') === 'checkbox' || in_array($key, ['privacy_ack', 'statute_ack'], true))
                            ? 'acknowledgements'
                            : 'profile'
                    ));
                }
                if ($patch !== []) {
                    $this->db->update('member_field_definitions', $patch, 'id = :id', ['id' => (int) $exists['id']]);
                }
                continue;
            }
            $this->db->insert('member_field_definitions', [
                'key' => $key,
                'field_type' => $field['field_type'],
                'label_json' => json_encode($field['label'], JSON_UNESCAPED_UNICODE),
                'is_required' => !empty($field['is_required']) ? 1 : 0,
                'validation_rule' => $field['validation_rule'],
                'is_enabled' => !empty($field['is_enabled']) ? 1 : 0,
                'sort_order' => $field['sort_order'] ?? $i,
                'form_step' => (string) ($field['form_step'] ?? (
                    (($field['field_type'] ?? '') === 'checkbox' || in_array($key, ['privacy_ack', 'statute_ack'], true))
                        ? 'acknowledgements'
                        : 'profile'
                )),
            ]);
        }

        $this->ensureDefaultFormStepRow();

        // Normalize legacy address field type to street.
        $this->db->query(
            "UPDATE member_field_definitions SET field_type = 'street', validation_rule = 'string|max:255'
             WHERE `key` = 'address' AND field_type IN ('address', 'text')"
        );
        $this->db->query(
            "UPDATE member_field_definitions SET field_type = 'gender', validation_rule = CASE WHEN is_required = 1 THEN 'required|in:M,F,X' ELSE 'in:M,F,X' END
             WHERE `key` = 'gender' AND field_type = 'select'"
        );
        $this->db->query(
            "UPDATE member_field_definitions SET field_type = 'language', validation_rule = CASE WHEN is_required = 1 THEN 'required|in:it,de,en,other' ELSE 'in:it,de,en,other' END
             WHERE `key` = 'preferred_language' AND field_type = 'select'"
        );

        $this->normalizeCoreArchiveFlags();
    }

    /** Keep identity/legal archive fields always enabled + required. */
    private function normalizeCoreArchiveFlags(): void
    {
        $rows = $this->db->fetchAll(
            "SELECT id, `key`, field_type, is_enabled, is_required, validation_rule
             FROM member_field_definitions
             WHERE `key` IN ('first_name','last_name','gender','preferred_language','birth_place','birth_date','fiscal_code','privacy_ack','statute_ack')"
        );
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            $type = MemberFieldTypes::resolve((string) ($row['field_type'] ?? 'text'), $key);
            $rule = MemberFieldTypes::validationRule($type, true);
            $enabled = (int) ($row['is_enabled'] ?? 0) === 1;
            $required = (int) ($row['is_required'] ?? 0) === 1;
            $currentRule = (string) ($row['validation_rule'] ?? '');
            if ($enabled && $required && $currentRule === $rule) {
                continue;
            }
            $this->db->update('member_field_definitions', [
                'is_enabled' => 1,
                'is_required' => 1,
                'validation_rule' => $rule,
            ], 'id = :id', ['id' => (int) $row['id']]);
        }
    }

    public function finalize(string $ip): void
    {
        file_put_contents(storage_path('installed.lock'), date('c'));
        $this->audit->log('install.completed', 'system', null, null, ['installed' => true], $ip);
    }

    public static function defaultFields(): array
    {
        return [
            [
                'key' => 'photo',
                'field_type' => 'photo',
                'label' => ['it' => 'Foto', 'de' => 'Foto', 'en' => 'Photo'],
                'is_required' => false,
                'validation_rule' => 'string|max:255',
                'is_enabled' => true,
                'sort_order' => 0,
            ],
            [
                'key' => 'first_name',
                'field_type' => 'text',
                'label' => ['it' => 'Nome', 'de' => 'Vorname', 'en' => 'First name'],
                'is_required' => true,
                'validation_rule' => 'required|string|max:120',
                'is_enabled' => true,
                'sort_order' => 10,
            ],
            [
                'key' => 'last_name',
                'field_type' => 'text',
                'label' => ['it' => 'Cognome', 'de' => 'Nachname', 'en' => 'Last name'],
                'is_required' => true,
                'validation_rule' => 'required|string|max:120',
                'is_enabled' => true,
                'sort_order' => 20,
            ],
            [
                'key' => 'gender',
                'field_type' => 'gender',
                'label' => ['it' => 'Sesso', 'de' => 'Geschlecht', 'en' => 'Gender'],
                'is_required' => true,
                'validation_rule' => 'required|in:M,F,X',
                'is_enabled' => true,
                'sort_order' => 25,
            ],
            [
                'key' => 'preferred_language',
                'field_type' => 'language',
                'label' => ['it' => 'Lingua', 'de' => 'Sprache', 'en' => 'Language'],
                'is_required' => true,
                'validation_rule' => 'required|in:it,de,en,other',
                'is_enabled' => true,
                'sort_order' => 28,
            ],
            [
                'key' => 'birth_place',
                'field_type' => 'birth_place',
                'label' => ['it' => 'Luogo di nascita', 'de' => 'Geburtsort', 'en' => 'Place of birth'],
                'is_required' => true,
                'validation_rule' => 'required|string|max:120',
                'is_enabled' => true,
                'sort_order' => 30,
            ],
            [
                'key' => 'birth_date',
                'field_type' => 'date',
                'label' => ['it' => 'Data di nascita', 'de' => 'Geburtsdatum', 'en' => 'Date of birth'],
                'is_required' => true,
                'validation_rule' => 'required|date',
                'is_enabled' => true,
                'sort_order' => 40,
            ],
            [
                'key' => 'fiscal_code',
                'field_type' => 'fiscal_code',
                'label' => ['it' => 'Codice fiscale', 'de' => 'Steuernummer (CF)', 'en' => 'Fiscal code'],
                'is_required' => true,
                'validation_rule' => 'required|fiscal_code',
                'is_enabled' => true,
                'sort_order' => 50,
            ],
            [
                'key' => 'city',
                'field_type' => 'city',
                'label' => ['it' => 'Città', 'de' => 'Stadt', 'en' => 'City'],
                'is_required' => false,
                'validation_rule' => 'string|max:120',
                'is_enabled' => true,
                'sort_order' => 60,
            ],
            [
                'key' => 'address',
                'field_type' => 'street',
                'label' => ['it' => 'Via', 'de' => 'Straße', 'en' => 'Street'],
                'is_required' => false,
                'validation_rule' => 'string|max:255',
                'is_enabled' => true,
                'sort_order' => 70,
            ],
            [
                'key' => 'house_number',
                'field_type' => 'house_number',
                'label' => ['it' => 'N. civico', 'de' => 'Hausnr.', 'en' => 'House no.'],
                'is_required' => false,
                'validation_rule' => 'string|max:20',
                'is_enabled' => true,
                'sort_order' => 75,
            ],
            [
                'key' => 'postal_code',
                'field_type' => 'postal_code',
                'label' => ['it' => 'CAP', 'de' => 'PLZ', 'en' => 'Postal code'],
                'is_required' => false,
                'validation_rule' => 'string|max:20',
                'is_enabled' => true,
                'sort_order' => 80,
            ],
            [
                'key' => 'email',
                'field_type' => 'email',
                'label' => ['it' => 'Email', 'de' => 'E-Mail', 'en' => 'Email'],
                'is_required' => false,
                'validation_rule' => 'email|max:190',
                'is_enabled' => true,
                'sort_order' => 90,
            ],
            [
                'key' => 'phone',
                'field_type' => 'phone',
                'label' => ['it' => 'Telefono', 'de' => 'Telefon', 'en' => 'Phone'],
                'is_required' => false,
                'validation_rule' => 'phone',
                'is_enabled' => true,
                'sort_order' => 100,
            ],
            [
                'key' => 'privacy_ack',
                'field_type' => 'checkbox',
                'label' => [
                    'it' => 'Presa visione dell’informativa privacy',
                    'de' => 'Datenschutzhinweis zur Kenntnis genommen',
                    'en' => 'Privacy notice acknowledged',
                ],
                'is_required' => true,
                'validation_rule' => 'accepted',
                'is_enabled' => true,
                'sort_order' => 200,
                'form_step' => 'acknowledgements',
            ],
            [
                'key' => 'statute_ack',
                'field_type' => 'checkbox',
                'label' => [
                    'it' => 'Accettazione dello statuto e del regolamento',
                    'de' => 'Satzung und Reglement akzeptiert',
                    'en' => 'Statute and regulations accepted',
                ],
                'is_required' => true,
                'validation_rule' => 'accepted',
                'is_enabled' => true,
                'sort_order' => 210,
                'form_step' => 'acknowledgements',
            ],

        ];
    }

    private function ensureDefaultFormStepRow(): void
    {
        try {
            $exists = $this->db->fetch('SELECT id FROM member_form_steps LIMIT 1');
        } catch (\Throwable) {
            return;
        }
        if ($exists) {
            return;
        }
        $this->db->insert('member_form_steps', [
            'key' => 'profile',
            'title_json' => json_encode([
                'it' => 'Anagrafica',
                'de' => 'Stammdaten',
                'en' => 'Profile',
            ], JSON_UNESCAPED_UNICODE),
            'sort_order' => 10,
        ]);
    }
}
