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

namespace Socly\Core\Plugin;

/**
 * Optional contract for plugins/add-ons that want their events in Settings → Workflow.
 *
 * Implement this interface next to PluginInterface. The Workflow page merges the groups of every
 * enabled plugin, and the AuditService bridge sends the mapped audit actions to WorkflowService.
 * A plugin can also call WorkflowService::dispatch('<event>', $vars) explicitly.
 */
interface WorkflowEventProviderInterface
{
    /**
     * Event groups shown in the Workflow event picker.
     *
     * The group and event labels are final display strings (already localised by the plugin with
     * its own AddonText); the workflow panel passes them through __() so plain strings stay as-is.
     *
     * @return array<string, array<string, string>> group label => [event key => event label]
     */
    public function workflowEventGroups(): array;

    /**
     * Audit action => workflow event key. Every action listed here is forwarded automatically to
     * WorkflowService when AuditService logs it (only if the event is declared above).
     *
     * @return array<string, string>
     */
    public function workflowAuditMap(): array;
}
