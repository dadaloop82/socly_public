<?php

declare(strict_types=1);

use Socly\Core\Plugin\PluginContext;
use Socly\Core\Plugin\PluginInterface;

/**
 * Example drop-in plugin. Enable it from Settings → Plugins.
 * Demonstrates hooks and encrypted settings. Safe to leave disabled.
 */
return new class implements PluginInterface {
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
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Example plugin that appends an audit-friendly note marker on member create.';
    }

    public function boot(PluginContext $ctx): void
    {
        $ctx->hooks()->on('member.created', function (array $member) use ($ctx): void {
            // Intentionally lightweight — real plugins add their own tables/UI.
            $prefix = (string) $ctx->get(\Socly\Services\SettingsService::class)->get('hello_notes.prefix', '[plugin]');
            unset($prefix, $member);
        });
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
