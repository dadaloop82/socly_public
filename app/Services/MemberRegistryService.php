<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Setup\AssociationLegalForms;

final class MemberRegistryService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly MemberService $members
    ) {
    }

    /** @return array<string,mixed>|null */
    public function build(?array $filters = null): ?array
    {
        $filters ??= [];
        $result = $this->members->search($filters, 1, 100000);
        $items = $result['items'];
        if ($items === []) {
            return null;
        }

        $rows = [];
        $incomplete = [];
        $n = 1;
        // Only data whose profile field is enabled *and* mandatory may be reported as
        // missing: a disabled or optional field must never flag a member in the registry.
        $fieldDefs = [];
        foreach ($this->members->fieldDefinitions(false) as $definition) {
            $key = trim((string) ($definition['key'] ?? ''));
            if ($key !== '') {
                $fieldDefs[$key] = $definition;
            }
        }
        $hasField = static function (array $keys) use ($fieldDefs): bool {
            foreach ($keys as $key) {
                $definition = $fieldDefs[$key] ?? null;
                if (is_array($definition) && !empty($definition['is_enabled'])) {
                    return true;
                }
            }
            return false;
        };
        $needsField = static function (array $keys) use ($fieldDefs): bool {
            foreach ($keys as $key) {
                $definition = $fieldDefs[$key] ?? null;
                if (is_array($definition) && !empty($definition['is_enabled']) && !empty($definition['is_required'])) {
                    return true;
                }
            }
            return false;
        };
        foreach ($items as $item) {
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            $rawFiscal = trim((string) ($fields['fiscal_code'] ?? $fields['tax_code'] ?? ''));
            $rawBirth = trim((string) ($fields['birth_date'] ?? ''));
            $birthPlace = trim((string) ($fields['birth_place'] ?? ''));
            $birthDate = format_date((string) ($fields['birth_date'] ?? ''));
            $birthBits = array_values(array_filter([$birthPlace, $birthDate]));
            $street = trim((string) ($fields['address'] ?? $fields['street'] ?? ''));
            $houseNumber = trim((string) ($fields['house_number'] ?? ''));
            $postalCode = trim((string) ($fields['postal_code'] ?? ''));
            $city = trim((string) ($fields['city'] ?? ''));
            $province = trim((string) ($fields['province'] ?? ''));
            $streetLine = trim($street . ($houseNumber !== '' ? ' ' . $houseNumber : ''));
            $cityLine = trim($postalCode . ($city !== '' ? ' ' . $city : '') . ($province !== '' ? ' (' . $province . ')' : ''));
            $residence = implode(', ', array_values(array_filter([$streetLine, $cityLine])));

            $row = [
                'progressive' => $n++,
                'member_number' => (string) ($item['member_number'] ?? ''),
                'last_name' => trim((string) ($fields['last_name'] ?? '')),
                'first_name' => trim((string) ($fields['first_name'] ?? '')),
                'fiscal_code' => trim((string) ($fields['fiscal_code'] ?? $fields['tax_code'] ?? '')),
                'birth' => $birthBits !== [] ? implode(', ', $birthBits) : '',
                'residence' => $residence,
                'applied_at' => format_date(substr((string) ($item['created_at'] ?? ''), 0, 10)),
                'admitted_at' => format_date((string) ($item['admitted_at'] ?? '')),
                'type_label' => localized($item['type_name_json'] ?? ''),
                'status' => (string) ($item['status'] ?? ''),
                'status_label' => __('members.status_' . ($item['status'] ?? 'active')),
            ];

            $missing = [];
            // Identity data is reported whenever the field is enabled (a registry is an
            // official document); optional fields never block it.
            if ($hasField(['fiscal_code']) && $rawFiscal === '') {
                $missing[] = __('setup.field_person_fiscal_code');
            }
            if ($hasField(['birth_date']) && $rawBirth === '') {
                $missing[] = __('setup.field_birth_date');
            }
            // Residence is only a required registry datum when its profile fields are
            // enabled *and* mandatory: a disabled or optional address must not flag members.
            if ($needsField(['address', 'house_number', 'postal_code', 'city', 'province']) && $residence === '') {
                $missing[] = __('members.registry_col_residence');
            }
            $row['missing'] = $missing;
            if ($missing !== []) {
                $incomplete[] = [
                    'name' => trim($row['last_name'] . ' ' . $row['first_name']),
                    'card' => $row['member_number'],
                    'missing' => $missing,
                ];
            }
            $rows[] = $row;
        }

        $statusCounts = [];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }

        $legalCode = strtoupper(trim((string) $this->settings->get('association.legal_name', '')));
        $legalLabel = '';
        if ($legalCode !== '') {
            $forms = AssociationLegalForms::all();
            if (isset($forms[$legalCode])) {
                $legalLabel = __($forms[$legalCode]);
            }
        }

        return [
            'association' => [
                'name' => (string) $this->settings->get('association.name', ''),
                'fiscal_code' => (string) $this->settings->get('association.fiscal_code', ''),
                'address' => (string) ($this->settings->get('association.address_full', '')
                    ?: $this->settings->get('association.address', '')),
                'legal_code' => $legalCode,
                'legal_label' => $legalLabel,
            ],
            'brand_primary' => (string) $this->settings->get('branding.primary', '#0D6E66'),
            'brand_accent' => (string) $this->settings->get('branding.accent', '#B84A1B'),
            'rows' => $rows,
            'incomplete' => $incomplete,
            'summary' => [
                'total' => count($rows),
                'active' => (int) ($statusCounts['active'] ?? 0),
            ],
            'generated_at' => date('d/m/Y H:i'),
            'total' => count($rows),
        ];
    }
}
