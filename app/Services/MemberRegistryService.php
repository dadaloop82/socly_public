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
            if ($rawFiscal === '') {
                $missing[] = __('setup.field_person_fiscal_code');
            }
            if ($rawBirth === '') {
                $missing[] = __('setup.field_birth_date');
            }
            if ($residence === '') {
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
