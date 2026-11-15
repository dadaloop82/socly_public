<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */


declare(strict_types=1);

use Socly\Core\Plugin\PluginContext;
use Socly\Core\Plugin\PluginInterface;
use Socly\Core\Plugin\WorkflowEventProviderInterface;
use Socly\Services\SettingsService;
use Socly\Services\WorkflowService;

/**
 * Example drop-in plugin. Enable it from Settings → Plugins.
 * Demonstrates hooks, encrypted settings and Workflow events. Safe to leave disabled.
 */
return new class implements PluginInterface, WorkflowEventProviderInterface {
    public function id(): string
    {
        return 'hello_notes';
    }

    public function name(): string
    {
        return 'Hello Notes';
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function description(): string
    {
        return 'Example plugin that appends an audit-friendly note marker on member create and exposes a Workflow event.';
    }

    public function boot(PluginContext $ctx): void
    {
        $ctx->hooks()->on('member.created', function (array $member) use ($ctx): void {
            // Real plugins add their own tables/UI. The example also declares a Workflow event so
            // Settings → Workflow lists it while the plugin is enabled.
            $prefix = (string) $ctx->get(SettingsService::class)->get('hello_notes.prefix', '[plugin]');
            $fields = is_array($member['fields'] ?? null) ? $member['fields'] : [];
            $email = strtolower(trim((string) ($fields['email'] ?? $member['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $name = trim((string) ($fields['first_name'] ?? '') . ' ' . (string) ($fields['last_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($member['member_number'] ?? ''));
            }
            $ctx->get(WorkflowService::class)->dispatch('hello_notes.member_note', [
                'email' => $email,
                'member_email' => $email,
                'member_name' => $name,
                'note_prefix' => $prefix,
                'association_name' => (string) $ctx->get(SettingsService::class)->get('association.name', ''),
            ], null, null);
        });
    }

    public function workflowEventGroups(): array
    {
        return [
            'Hello Notes' => [
                'hello_notes.member_note' => 'Hello Notes · member note',
            ],
        ];
    }

    public function workflowAuditMap(): array
    {
        return [];
    }

    public function registerRoutes(): array
    {
        return [];
    }

    public function registerMenu(): array
    {
        return [];
    }

    public function registerSettings(): array
    {
        return [
            'hello_notes.prefix' => [
                'label' => 'Note prefix',
                'encrypted' => false,
                'validate' => 'string|max:50',
            ],
            'hello_notes.secret' => [
                'label' => 'Secret (encrypted)',
                'encrypted' => true,
                'validate' => 'string|max:120',
            ],
        ];
    }

    public function migrations(): array
    {
        return [];
    }
};
