<?php

declare(strict_types=1);

namespace Socly\Support;

/**
 * Stable permission keys — avoid the legacy churn of renamed permission strings.
 */
final class Permission
{
    public const DASHBOARD_VIEW = 'dashboard.view';
    public const MEMBERS_VIEW = 'members.view';
    public const MEMBERS_MANAGE = 'members.manage';
    public const MEMBERS_DELETE = 'members.delete';
    public const PAYMENTS_MANAGE = 'payments.manage';
    public const SETTINGS_MANAGE = 'settings.manage';
    public const USERS_MANAGE = 'users.manage';
    public const PLUGINS_MANAGE = 'plugins.manage';
    public const AUDIT_VIEW = 'audit.view';
    public const TREASURY_VIEW = 'treasury.view';
    public const TREASURY_MANAGE = 'treasury.manage';
    public const ORG_VIEW = 'org.view';
    public const ORG_MANAGE = 'org.manage';
    public const DEADLINES_VIEW = 'deadlines.view';
    public const DEADLINES_MANAGE = 'deadlines.manage';
    public const DOCUMENTS_VIEW = 'documents.view';
    public const DOCUMENTS_MANAGE = 'documents.manage';

    /** @return array<string, string> key => description */
    public static function catalogue(): array
    {
        return [
            self::DASHBOARD_VIEW => 'View dashboard',
            self::MEMBERS_VIEW => 'View members',
            self::MEMBERS_MANAGE => 'Create and edit members',
            self::MEMBERS_DELETE => 'Delete members',
            self::PAYMENTS_MANAGE => 'Manage payments',
            self::SETTINGS_MANAGE => 'Manage settings',
            self::USERS_MANAGE => 'Manage users',
            self::PLUGINS_MANAGE => 'Manage plugins',
            self::AUDIT_VIEW => 'View audit log',
            self::TREASURY_VIEW => 'View treasury register',
            self::TREASURY_MANAGE => 'Manage treasury movements',
            self::ORG_VIEW => 'View org chart and roles',
            self::ORG_MANAGE => 'Manage org roles',
            self::DEADLINES_VIEW => 'View deadlines calendar',
            self::DEADLINES_MANAGE => 'Manage deadlines',
            self::DOCUMENTS_VIEW => 'View documents archive',
            self::DOCUMENTS_MANAGE => 'Manage documents',
        ];
    }
}
