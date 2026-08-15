<?php

declare(strict_types=1);

namespace Socly\Core\Plugin;

interface PluginInterface
{
    public function id(): string;

    public function name(): string;

    public function version(): string;

    public function description(): string;

    public function boot(PluginContext $ctx): void;

    /** @return list<array{0:string,1:string,2:callable|array,3?:?string}> */
    public function registerRoutes(): array;

    /** @return list<array{label:string,path:string,permission?:string}> */
    public function registerMenu(): array;

    /**
     * @return array<string, array{label:string,encrypted?:bool,validate?:string,type?:string}>
     */
    public function registerSettings(): array;

    /** @return array<string, string> version => SQL */
    public function migrations(): array;
}
