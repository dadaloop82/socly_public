<?php

declare(strict_types=1);

namespace Socly\Middleware;

use Socly\Core\Http\Request;
use Socly\Services\SetupService;

/**
 * Allows geo/helper APIs during open setup, otherwise requires auth.
 */
final class SetupOrAuthMiddleware
{
    public function __construct(
        private readonly SetupService $setup,
        private readonly AuthMiddleware $auth
    ) {
    }

    public function handle(Request $request): bool
    {
        try {
            if (!$this->setup->isComplete()) {
                return true;
            }
        } catch (\Throwable) {
        }

        return $this->auth->handle($request);
    }
}
