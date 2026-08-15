<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Core\Validator;
use Socly\Support\Permission;

final class UserService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
        private readonly Validator $validator,
        private readonly AuthService $auth
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function all(bool $includeSystemAdmins = false): array
    {
        $sql = 'SELECT id, email, name, locale, is_system_admin, is_active, created_at FROM users';
        if (!$includeSystemAdmins) {
            $sql .= ' WHERE is_system_admin = 0';
        }
        $sql .= ' ORDER BY name ASC';
        return $this->db->fetchAll($sql);
    }

    public function find(int $id): ?array
    {
        $user = $this->db->fetch(
            'SELECT id, email, name, locale, is_system_admin, is_active, created_at FROM users WHERE id = :id',
            ['id' => $id]
        );
        if (!$user) {
            return null;
        }
        $user['permission_keys'] = array_column($this->db->fetchAll(
            'SELECT p.`key` FROM permissions p
             INNER JOIN user_permissions up ON up.permission_id = p.id
             WHERE up.user_id = :id',
            ['id' => $id]
        ), 'key');
        return $user;
    }

    /** True when at least one non–system-admin user exists (association Admin). */
    public function hasAssociationAdmin(): bool
    {
        $row = $this->db->fetch(
            'SELECT id FROM users WHERE is_system_admin = 0 AND is_active = 1 LIMIT 1'
        );
        return $row !== null;
    }

    /**
     * Create the association Admin with full catalogue permissions (not SuperAdmin).
     *
     * @return array{ok:bool,errors?:array<string,string>,id?:int}
     */
    public function createAssociationAdmin(array $data, string $ip): array
    {
        $data['is_active'] = 1;
        $data['locale'] = $data['locale'] ?? 'it';
        return $this->create($data, array_keys(Permission::catalogue()), $ip);
    }

    /** @return list<array<string,mixed>> */
    public function permissions(): array
    {
        return $this->db->fetchAll('SELECT * FROM permissions ORDER BY `key`');
    }

    /** @return array{ok:bool,errors?:array,id?:int} */
    public function create(array $data, array $permissionKeys, string $ip): array
    {
        if (!$this->validator->validate($data, [
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'password' => 'required|string|min:8|confirmed',
            'locale' => 'required|in:it,de,en',
        ])) {
            return ['ok' => false, 'errors' => $this->validator->firstErrors()];
        }
        if ($this->db->fetch('SELECT id FROM users WHERE email = :e', ['e' => $data['email']])) {
            return ['ok' => false, 'errors' => ['email' => __('validation.unique')]];
        }
        $id = $this->db->insert('users', [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'locale' => $data['locale'],
            'is_system_admin' => 0,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ]);
        $this->syncPermissions($id, $permissionKeys);
        $this->audit->log('user.created', 'user', (string) $id, null, $this->find($id), $ip);
        return ['ok' => true, 'id' => $id];
    }

    /** @return array{ok:bool,errors?:array} */
    public function update(int $id, array $data, array $permissionKeys, string $ip): array
    {
        $before = $this->find($id);
        if (!$before) {
            return ['ok' => false, 'errors' => ['id' => __('validation.required')]];
        }
        $rules = [
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'locale' => 'required|in:it,de,en',
        ];
        if (!empty($data['password'])) {
            $rules['password'] = 'string|min:8|confirmed';
        }
        if (!$this->validator->validate($data, $rules)) {
            return ['ok' => false, 'errors' => $this->validator->firstErrors()];
        }
        $dup = $this->db->fetch('SELECT id FROM users WHERE email = :e AND id <> :id', ['e' => $data['email'], 'id' => $id]);
        if ($dup) {
            return ['ok' => false, 'errors' => ['email' => __('validation.unique')]];
        }
        $payload = [
            'name' => $data['name'],
            'email' => $data['email'],
            'locale' => $data['locale'],
            'is_active' => !empty($before['is_system_admin']) ? 1 : (!empty($data['is_active']) ? 1 : 0),
        ];
        if (!empty($data['password'])) {
            $payload['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        $this->db->update('users', $payload, 'id = :id', ['id' => $id]);
        if (empty($before['is_system_admin'])) {
            $this->syncPermissions($id, $permissionKeys);
        }
        $after = $this->find($id);
        $this->audit->log('user.updated', 'user', (string) $id, $before, $after, $ip);
        if ((int) (auth_user()['id'] ?? 0) === $id) {
            $this->auth->refreshSessionUser();
        }
        return ['ok' => true];
    }

    /** @return array{ok:bool,error?:string} */
    public function delete(int $id, string $ip): array
    {
        $user = $this->find($id);
        if (!$user) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (!empty($user['is_system_admin'])) {
            return ['ok' => false, 'error' => 'system_admin'];
        }
        $this->db->query('DELETE FROM users WHERE id = :id', ['id' => $id]);
        $this->audit->log('user.deleted', 'user', (string) $id, $user, null, $ip);
        return ['ok' => true];
    }

    private function syncPermissions(int $userId, array $keys): void
    {
        $this->db->query('DELETE FROM user_permissions WHERE user_id = :id', ['id' => $userId]);
        foreach ($keys as $key) {
            $perm = $this->db->fetch('SELECT id FROM permissions WHERE `key` = :k', ['k' => $key]);
            if ($perm) {
                $this->db->insert('user_permissions', [
                    'user_id' => $userId,
                    'permission_id' => $perm['id'],
                ]);
            }
        }
    }

    /** @return list<array{key:string,label_key:string,description_key:string,permissions:list<string>}> */
    public static function roleTemplates(): array
    {
        return [
            [
                'key' => 'president',
                'label_key' => 'org.role_president',
                'description_key' => 'org.role_president_desc',
                'permissions' => array_keys(Permission::catalogue()),
            ],
            [
                'key' => 'treasurer',
                'label_key' => 'org.role_treasurer',
                'description_key' => 'org.role_treasurer_desc',
                'permissions' => [
                    Permission::DASHBOARD_VIEW,
                    Permission::MEMBERS_VIEW,
                    Permission::MEMBERS_MANAGE,
                    Permission::PAYMENTS_MANAGE,
                    Permission::TREASURY_VIEW,
                    Permission::TREASURY_MANAGE,
                    Permission::DEADLINES_VIEW,
                    Permission::DEADLINES_MANAGE,
                ],
            ],
            [
                'key' => 'secretary',
                'label_key' => 'org.role_secretary',
                'description_key' => 'org.role_secretary_desc',
                'permissions' => [
                    Permission::DASHBOARD_VIEW,
                    Permission::MEMBERS_VIEW,
                    Permission::MEMBERS_MANAGE,
                    Permission::PAYMENTS_MANAGE,
                    Permission::DEADLINES_VIEW,
                    Permission::DEADLINES_MANAGE,
                    Permission::DOCUMENTS_VIEW,
                    Permission::DOCUMENTS_MANAGE,
                ],
            ],
            [
                'key' => 'board',
                'label_key' => 'org.role_board',
                'description_key' => 'org.role_board_desc',
                'permissions' => [
                    Permission::DASHBOARD_VIEW,
                    Permission::MEMBERS_VIEW,
                    Permission::DEADLINES_VIEW,
                    Permission::DOCUMENTS_VIEW,
                    Permission::DOCUMENTS_MANAGE,
                    Permission::ORG_VIEW,
                ],
            ],
            [
                'key' => 'volunteer',
                'label_key' => 'org.role_volunteer',
                'description_key' => 'org.role_volunteer_desc',
                'permissions' => [
                    Permission::DASHBOARD_VIEW,
                    Permission::DEADLINES_VIEW,
                ],
            ],
        ];
    }
}
