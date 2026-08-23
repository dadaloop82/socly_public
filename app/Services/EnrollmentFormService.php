<?php

declare(strict_types=1);

namespace Socly\Services;

final class EnrollmentFormService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly MemberService $members
    ) {
    }

    /** @return array<string,mixed>|null */
    public function build(int $memberId): ?array
    {
        $member = $this->members->find($memberId);
        if (!$member) {
            return null;
        }

        $decode = static function (mixed $raw): array {
            if (is_array($raw)) {
                return $raw;
            }
            $decoded = json_decode((string) $raw, true);
            return is_array($decoded) ? $decoded : ['it' => (string) $raw];
        };

        $fields = is_array($member['fields'] ?? null) ? $member['fields'] : [];
        $defs = $this->members->fieldDefinitions(true);
        $displayFields = [];
        foreach ($defs as $def) {
            $key = (string) ($def['key'] ?? '');
            if ($key === '' || in_array($key, ['photo', 'privacy_ack', 'statute_ack'], true)) {
                continue;
            }
            $type = \Socly\Support\MemberFieldTypes::resolve((string) ($def['field_type'] ?? 'text'), $key);
            if ($type === \Socly\Support\MemberFieldTypes::PHOTO || $type === \Socly\Support\MemberFieldTypes::CHECKBOX) {
                continue;
            }
            $raw = $fields[$key] ?? null;
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                continue;
            }
            $displayFields[] = [
                'label' => localized($def['label_json'] ?? $key) ?: $key,
                'value' => $this->formatFieldValue($type, $raw),
            ];
        }

        $gdprEnabled = (string) ($this->settings->get('gdpr.enabled', '0') ?: '0') === '1';

        return [
            'member' => $member,
            'association' => [
                'name' => (string) $this->settings->get('association.name', ''),
                'fiscal_code' => (string) $this->settings->get('association.fiscal_code', ''),
                'vat_number' => (string) $this->settings->get('association.vat_number', ''),
                'address' => (string) ($this->settings->get('association.address_full', '')
                    ?: $this->settings->get('association.address', '')),
                'email' => (string) $this->settings->get('association.email', ''),
                'phone' => (string) $this->settings->get('association.phone', ''),
            ],
            'fields' => $displayFields,
            'type_label' => localized($member['type_name_json'] ?? ''),
            'period_label' => (string) ($member['period_label'] ?? ''),
            'privacy_text' => $gdprEnabled ? localized($decode($this->settings->get('legal.privacy', ''))) : '',
            'statute_text' => localized($decode($this->settings->get('legal.statute', ''))),
            'generated_at' => date('d/m/Y'),
        ];
    }

    private function formatFieldValue(string $type, mixed $raw): string
    {
        if ($type === \Socly\Support\MemberFieldTypes::DATE && is_string($raw) && $raw !== '') {
            $ts = strtotime($raw);
            return $ts ? date('d/m/Y', $ts) : $raw;
        }
        if (is_bool($raw)) {
            return $raw ? __('common.yes') : __('common.no');
        }
        return trim((string) $raw);
    }
}
