<?php

declare(strict_types=1);

namespace Socly\Components;

use Socly\Support\Permission;

/**
 * Base-package components (free, included in SOCLY Base).
 *
 * @phpstan-type ComponentDef array{
 *   key: string,
 *   name_key: string,
 *   description_key: string,
 *   price_key: string,
 *   nav_label_key: string,
 *   path: string,
 *   permission: string,
 *   icon: string,
 *   default_enabled: bool,
 *   sort_order: int,
 *   has_settings: bool,
 *   settings_key?: string
 * }
 */
final class ComponentRegistry
{
    /** @return list<ComponentDef> */
    public static function all(): array
    {
        return [
            [
                'key' => 'members',
                'name_key' => 'components.members.name',
                'description_key' => 'components.members.description',
                'price_key' => 'components.price_included',
                'nav_label_key' => 'nav.members',
                'path' => '/members',
                'permission' => Permission::MEMBERS_VIEW,
                'icon' => 'users',
                'default_enabled' => true,
                'sort_order' => 10,
                'has_settings' => false,
            ],
            [
                'key' => 'treasury',
                'name_key' => 'components.treasury.name',
                'description_key' => 'components.treasury.description',
                'price_key' => 'components.price_included',
                'nav_label_key' => 'nav.treasury',
                'path' => '/treasury',
                'permission' => Permission::TREASURY_VIEW,
                'icon' => 'wallet',
                'default_enabled' => true,
                'sort_order' => 20,
                'has_settings' => true,
                'settings_key' => 'components.config.treasury',
            ],
            [
                'key' => 'org_roles',
                'name_key' => 'components.org_roles.name',
                'description_key' => 'components.org_roles.description',
                'price_key' => 'components.price_included',
                'nav_label_key' => 'nav.org',
                'path' => '/org',
                'permission' => Permission::ORG_VIEW,
                'icon' => 'org',
                'default_enabled' => true,
                'sort_order' => 30,
                'has_settings' => true,
                'settings_key' => 'components.config.org_roles',
            ],
            [
                'key' => 'deadlines',
                'name_key' => 'components.deadlines.name',
                'description_key' => 'components.deadlines.description',
                'price_key' => 'components.price_included',
                'nav_label_key' => 'nav.deadlines',
                'path' => '/deadlines',
                'permission' => Permission::DEADLINES_VIEW,
                'icon' => 'calendar',
                'default_enabled' => true,
                'sort_order' => 40,
                'has_settings' => true,
                'settings_key' => 'components.config.deadlines',
            ],
            [
                'key' => 'documents',
                'name_key' => 'components.documents.name',
                'description_key' => 'components.documents.description',
                'price_key' => 'components.price_included',
                'nav_label_key' => 'nav.documents',
                'path' => '/documents',
                'permission' => Permission::DOCUMENTS_VIEW,
                'icon' => 'file',
                'default_enabled' => true,
                'sort_order' => 50,
                'has_settings' => true,
                'settings_key' => 'components.config.documents',
            ],
        ];
    }

    /** @return ComponentDef|null */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $component) {
            if ($component['key'] === $key) {
                return $component;
            }
        }
        return null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(static fn (array $c): string => $c['key'], self::all());
    }
}
