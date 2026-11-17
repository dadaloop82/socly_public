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

interface PluginInterface
{
    public function id(): string;

    public function name(): string;

    public function version(): string;

    public function description(): string;

    public function boot(PluginContext $ctx): void;

    /**
     * Route: [method, path, action, permission?, middleware?].
     * Middleware defaults to ['mw.csrf','mw.auth']; pass e.g. ['mw.csrf'] for a
     * public add-on page (the kiosk) that must work without a logged-in operator.
     *
     * @return list<array{0:string,1:string,2:callable|array,3?:?string,4?:list<string>}>
     */
    public function registerRoutes(): array;

    /**
     * Menu entry: [label, path, permission?, group?].
     * `group` nests the entry under the core nav item with that key (e.g. `members`).
     * The sidebar appends the plugin `version()` in small type at the end of each entry.
     *
     * @return list<array{label:string,path:string,permission?:string,group?:string,version?:string}>
     */
    public function registerMenu(): array;

    /**
     * @return array<string, array{label:string,encrypted?:bool,validate?:string,type?:string}>
     */
    public function registerSettings(): array;

    /** @return array<string, string> version => SQL */
    public function migrations(): array;
}
