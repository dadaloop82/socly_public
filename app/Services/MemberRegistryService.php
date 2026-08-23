<?php

declare(strict_types=1);

namespace Socly\Services;

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
        $n = 1;
        foreach ($items as $item) {
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            $rows[] = [
                'progressive' => $n++,
                'member_number' => (string) ($item['member_number'] ?? ''),
                'last_name' => trim((string) ($fields['last_name'] ?? '')),
                'first_name' => trim((string) ($fields['first_name'] ?? '')),
                'fiscal_code' => trim((string) ($fields['fiscal_code'] ?? $fields['tax_code'] ?? '')),
                'applied_at' => format_date(substr((string) ($item['created_at'] ?? ''), 0, 10)),
                'admitted_at' => format_date((string) ($item['admitted_at'] ?? '')),
                'type_label' => localized($item['type_name_json'] ?? ''),
                'status' => (string) ($item['status'] ?? ''),
                'status_label' => __('members.status_' . ($item['status'] ?? 'active')),
            ];
        }

        return [
            'association' => [
                'name' => (string) $this->settings->get('association.name', ''),
                'fiscal_code' => (string) $this->settings->get('association.fiscal_code', ''),
                'address' => (string) ($this->settings->get('association.address_full', '')
                    ?: $this->settings->get('association.address', '')),
            ],
            'rows' => $rows,
            'generated_at' => date('d/m/Y H:i'),
            'total' => count($rows),
        ];
    }
}
