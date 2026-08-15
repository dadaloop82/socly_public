<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;

/**
 * Association people with role hierarchy (president → board → auditors, …).
 */
final class AssociationPeopleService
{
    public const ROLE_PRESIDENT = 'president';
    public const ROLE_VICE_PRESIDENT = 'vice_president';
    public const ROLE_SECRETARY = 'secretary';
    public const ROLE_TREASURER = 'treasurer';
    public const ROLE_BOARD = 'board';
    public const ROLE_AUDITOR = 'auditor';

    /** @var list<string> */
    public const SYSTEM_ROLE_KEYS = [
        self::ROLE_PRESIDENT,
        self::ROLE_VICE_PRESIDENT,
        self::ROLE_SECRETARY,
        self::ROLE_TREASURER,
        self::ROLE_BOARD,
        self::ROLE_AUDITOR,
    ];

    /** @deprecated Use ROLE_* constants / roles() catalog */
    public const ROLE_PRESIDENT_ALIAS = self::ROLE_PRESIDENT;

    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function roles(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM association_roles';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY hierarchy_level ASC, sort_order ASC, id ASC';
        return $this->db->fetchAll($sql);
    }

    /** @return array<string, mixed>|null */
    public function role(string $key): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM association_roles WHERE `key` = :k LIMIT 1',
            ['k' => $key]
        );
    }

    /** @return list<string> */
    public function roleKeys(): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['key'],
            $this->roles()
        );
    }

    /** @param array<string, mixed> $role */
    public function roleLabel(array $role): string
    {
        $custom = trim((string) ($role['custom_label'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        $key = (string) ($role['key'] ?? '');
        $labelKey = (string) ($role['label_key'] ?? ('association.role_' . $key));
        return __($labelKey);
    }

    public function isSystemRole(string $key): bool
    {
        return in_array($key, self::SYSTEM_ROLE_KEYS, true);
    }

    /**
     * Extra organ blocks (below auditors).
     *
     * @return list<array<string, mixed>>
     */
    public function customOrgans(): array
    {
        return array_values(array_filter(
            $this->roles(),
            fn (array $r): bool => !$this->isSystemRole((string) ($r['key'] ?? ''))
        ));
    }

    /**
     * @return array{ok:bool,key?:string,errors?:array<string,string>}
     */
    public function createOrgan(string $label): array
    {
        $label = trim($label);
        if ($label === '') {
            return ['ok' => false, 'errors' => ['label' => __('validation.required')]];
        }
        if (mb_strlen($label) > 80) {
            return ['ok' => false, 'errors' => ['label' => __('validation.max_string', ['max' => '80'])]];
        }
        $slug = $this->slugifyOrgan($label);
        if ($slug === '') {
            return ['ok' => false, 'errors' => ['label' => __('org.organ_invalid')]];
        }
        $key = 'organ_' . $slug;
        $n = 0;
        while ($this->role($key) !== null) {
            $n++;
            $key = 'organ_' . $slug . '_' . $n;
            if ($n > 50) {
                return ['ok' => false, 'errors' => ['label' => __('org.organ_invalid')]];
            }
        }
        $max = $this->db->fetch('SELECT COALESCE(MAX(hierarchy_level), 60) AS m FROM association_roles');
        $level = max(70, ((int) ($max['m'] ?? 60)) + 10);
        $sort = $this->db->fetch('SELECT COALESCE(MAX(sort_order), 60) AS m FROM association_roles');
        $this->db->insert('association_roles', [
            'key' => $key,
            'label_key' => 'association.role_custom',
            'custom_label' => mb_substr($label, 0, 120),
            'hierarchy_level' => $level,
            'is_unique' => 0,
            'requires_residence' => 0,
            'requires_mandate' => 0,
            'sort_order' => ((int) ($sort['m'] ?? 60)) + 10,
            'is_active' => 1,
            'is_system' => 0,
        ]);
        return ['ok' => true, 'key' => $key];
    }

    /**
     * @return array{ok:bool,errors?:array<string,string>}
     */
    public function deleteOrgan(string $key): array
    {
        $key = trim($key);
        $role = $this->role($key);
        if ($role === null || $this->isSystemRole($key) || !empty($role['is_system'])) {
            return ['ok' => false, 'errors' => ['organ' => __('org.organ_cannot_delete')]];
        }
        if ($this->countByRole($key) > 0) {
            return ['ok' => false, 'errors' => ['organ' => __('org.organ_not_empty')]];
        }
        $this->db->query('DELETE FROM association_roles WHERE `key` = :k AND is_system = 0', ['k' => $key]);
        return ['ok' => true];
    }

    private function slugifyOrgan(string $value): string
    {
        $value = trim(mb_strtolower($value));
        if ($value === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($trans) && $trans !== '') {
                $value = $trans;
            }
        }
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');
        return mb_substr($value, 0, 28);
    }

    /**
     * All people ordered by hierarchy then sort_order.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->fetchAll(
            'SELECT p.*, r.label_key AS role_label_key, r.hierarchy_level,
                    r.is_unique AS role_is_unique, r.requires_residence, r.requires_mandate
             FROM association_people p
             INNER JOIN association_roles r ON r.`key` = p.role_key
             WHERE p.is_active = 1
             ORDER BY r.hierarchy_level ASC, p.sort_order ASC, p.id ASC'
        );
    }

    /** @return list<array<string, mixed>> */
    public function listByRole(string $role): array
    {
        return $this->db->fetchAll(
            'SELECT p.*, r.label_key AS role_label_key, r.hierarchy_level
             FROM association_people p
             INNER JOIN association_roles r ON r.`key` = p.role_key
             WHERE p.role_key = :role AND p.is_active = 1
             ORDER BY p.sort_order ASC, p.id ASC',
            ['role' => $role]
        );
    }

    public function countByRole(string $role): int
    {
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS c FROM association_people WHERE role_key = :role AND is_active = 1',
            ['role' => $role]
        );
        return (int) ($row['c'] ?? 0);
    }

    /** @return array<string, mixed>|null */
    public function getPresident(): ?array
    {
        $rows = $this->listByRole(self::ROLE_PRESIDENT);
        return $rows[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        return $this->db->fetch(
            'SELECT p.*, r.label_key AS role_label_key, r.custom_label AS role_custom_label,
                    r.hierarchy_level, r.is_unique AS role_is_unique,
                    r.requires_residence, r.requires_mandate
             FROM association_people p
             INNER JOIN association_roles r ON r.`key` = p.role_key
             WHERE p.id = :id
             LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Create or update one person. Returns validation errors or the id.
     *
     * @param array<string, mixed> $input
     * @return array{ok:bool,id?:int,errors?:array<string,string>}
     */
    public function savePerson(array $input, ?int $id = null): array
    {
        $role = trim((string) ($input['role_key'] ?? ''));
        if ($role === '' || $this->role($role) === null) {
            return ['ok' => false, 'errors' => ['role_key' => __('validation.in')]];
        }

        $first = trim((string) ($input['first_name'] ?? ''));
        $last = trim((string) ($input['last_name'] ?? ''));
        $cf = strtoupper(preg_replace('/\s+/', '', (string) ($input['fiscal_code'] ?? '')) ?? '');
        if ($first === '' || $last === '' || $cf === '') {
            return ['ok' => false, 'errors' => ['first_name' => __('validation.required')]];
        }

        $roleMeta = $this->role($role) ?? [];
        $requiresResidence = !empty($roleMeta['requires_residence']);
        $requiresMandate = !empty($roleMeta['requires_mandate']);
        $isUnique = !empty($roleMeta['is_unique']);

        $row = $this->normalizePerson($input, $role);
        if ($row === null) {
            return ['ok' => false, 'errors' => ['first_name' => __('validation.required')]];
        }

        if ($requiresResidence) {
            foreach (['city', 'postal_code', 'address', 'house_number'] as $field) {
                if (trim((string) ($row[$field] ?? '')) === '') {
                    return ['ok' => false, 'errors' => [$field => __('validation.required')]];
                }
            }
        }
        if ($requiresMandate) {
            foreach (['appointed_at', 'mandate_ends_at'] as $field) {
                if (trim((string) ($row[$field] ?? '')) === '') {
                    return ['ok' => false, 'errors' => [$field => __('validation.required')]];
                }
            }
        }

        if ($isUnique) {
            $existingUnique = $this->db->fetch(
                'SELECT id FROM association_people
                 WHERE role_key = :role AND is_active = 1
                   AND (:id = 0 OR id <> :id2)
                 LIMIT 1',
                ['role' => $role, 'id' => $id ?? 0, 'id2' => $id ?? 0]
            );
            if ($existingUnique) {
                return ['ok' => false, 'errors' => ['role_key' => __('org.unique_role')]];
            }
        }

        try {
            if ($id !== null && $id > 0) {
                $current = $this->find($id);
                if ($current === null) {
                    return ['ok' => false, 'errors' => ['id' => __('errors.404')]];
                }
                unset($row['is_active']);
                $this->db->update('association_people', $row, 'id = :id', ['id' => $id]);
                return ['ok' => true, 'id' => $id];
            }

            $maxOrder = $this->db->fetch(
                'SELECT COALESCE(MAX(sort_order), -1) AS m FROM association_people WHERE role_key = :role',
                ['role' => $role]
            );
            $row['sort_order'] = ((int) ($maxOrder['m'] ?? -1)) + 1;
            $newId = $this->db->insert('association_people', $row);
            return ['ok' => true, 'id' => $newId];
        } catch (\Throwable) {
            return ['ok' => false, 'errors' => ['role_key' => __('org.unique_role')]];
        }
    }

    /** @return array{ok:bool,errors?:array<string,string>} */
    public function deletePerson(int $id): array
    {
        $person = $this->find($id);
        if ($person === null) {
            return ['ok' => false, 'errors' => ['id' => __('errors.404')]];
        }
        if ((string) ($person['role_key'] ?? '') === self::ROLE_PRESIDENT) {
            $count = $this->countByRole(self::ROLE_PRESIDENT);
            if ($count <= 1) {
                return ['ok' => false, 'errors' => ['role_key' => __('org.cannot_delete_president')]];
            }
        }
        $this->db->query('DELETE FROM association_people WHERE id = :id', ['id' => $id]);
        return ['ok' => true];
    }

    /**
     * If the role is unique and already filled, return that person id.
     */
    public function uniquePersonId(string $role): ?int
    {
        $meta = $this->role($role);
        if ($meta === null || empty($meta['is_unique'])) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT id FROM association_people WHERE role_key = :role AND is_active = 1 ORDER BY id ASC LIMIT 1',
            ['role' => $role]
        );
        return $row ? (int) $row['id'] : null;
    }

    /**
     * Replace every person for one role.
     *
     * @param list<array<string, mixed>> $people
     */
    public function replaceRole(string $role, array $people): void
    {
        if ($this->role($role) === null) {
            throw new \InvalidArgumentException('Unknown association role: ' . $role);
        }

        $this->db->beginTransaction();
        try {
            $this->db->query('DELETE FROM association_people WHERE role_key = :role', ['role' => $role]);
            $order = 0;
            foreach ($people as $person) {
                $row = $this->normalizePerson($person, $role);
                if ($row === null) {
                    continue;
                }
                $row['sort_order'] = $order++;
                $this->db->insert('association_people', $row);
            }
            $this->assertUniqueRoles();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Replace the whole association hierarchy from a flat people list.
     *
     * @param list<array<string, mixed>> $people
     */
    public function replaceAll(array $people): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->query('DELETE FROM association_people');
            $orderByRole = [];
            foreach ($people as $person) {
                $role = trim((string) ($person['role_key'] ?? $person['role'] ?? ''));
                if ($role === '' || $this->role($role) === null) {
                    continue;
                }
                $row = $this->normalizePerson($person, $role);
                if ($row === null) {
                    continue;
                }
                $orderByRole[$role] = ($orderByRole[$role] ?? 0);
                $row['sort_order'] = $orderByRole[$role]++;
                $this->db->insert('association_people', $row);
            }
            $this->assertUniqueRoles();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function clearAll(): void
    {
        $this->db->query('DELETE FROM association_people');
    }

    /**
     * @param array<string, mixed> $person
     * @return array<string, mixed>|null
     */
    private function normalizePerson(array $person, string $role): ?array
    {
        $first = trim((string) ($person['first_name'] ?? ''));
        $last = trim((string) ($person['last_name'] ?? ''));
        $cf = strtoupper(preg_replace('/\s+/', '', (string) ($person['fiscal_code'] ?? '')) ?? '');
        if ($first === '' && $last === '' && $cf === '') {
            return null;
        }

        return [
            'role_key' => $role,
            'first_name' => $first,
            'last_name' => $last,
            'fiscal_code' => $cf,
            'birth_date' => $this->nullIfEmpty($person['birth_date'] ?? null),
            'gender' => $this->nullIfEmpty(isset($person['gender']) ? strtoupper((string) $person['gender']) : null),
            'birth_place' => $this->nullIfEmpty($person['birth_place'] ?? null),
            'email' => $this->nullIfEmpty($person['email'] ?? null),
            'phone' => $this->nullIfEmpty($person['phone'] ?? null),
            'city' => $this->nullIfEmpty($person['city'] ?? null),
            'postal_code' => $this->nullIfEmpty($person['postal_code'] ?? null),
            'address' => $this->nullIfEmpty($person['address'] ?? null),
            'house_number' => $this->nullIfEmpty($person['house_number'] ?? null),
            'appointed_at' => $this->nullIfEmpty($person['appointed_at'] ?? null),
            'mandate_ends_at' => $this->nullIfEmpty($person['mandate_ends_at'] ?? null),
            'notes' => $this->nullIfEmpty($person['notes'] ?? null),
            'is_active' => 1,
        ];
    }

    private function assertUniqueRoles(): void
    {
        $dup = $this->db->fetch(
            'SELECT p.role_key, COUNT(*) AS c
             FROM association_people p
             INNER JOIN association_roles r ON r.`key` = p.role_key
             WHERE r.is_unique = 1 AND p.is_active = 1
             GROUP BY p.role_key
             HAVING c > 1
             LIMIT 1'
        );
        if ($dup) {
            throw new \RuntimeException('Role must be unique: ' . (string) $dup['role_key']);
        }
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));
        return $v === '' ? null : $v;
    }
}
