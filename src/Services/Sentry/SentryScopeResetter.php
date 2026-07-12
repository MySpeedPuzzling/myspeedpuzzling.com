<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sentry;

use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Contracts\Service\ResetInterface;

/**
 * In FrankenPHP worker mode the Sentry Hub and its root Scope live for the whole
 * worker process. sentry-symfony isolates scopes for sub-requests, console commands
 * and messenger messages, but NOT for main HTTP requests — breadcrumbs, user identity
 * (set by LoginListener), client IP and route tag leak from one request to the next,
 * attributing errors to the wrong user.
 */
final class SentryScopeResetter implements ResetInterface
{
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    public function reset(): void
    {
        $this->hub->configureScope(static function (Scope $scope): void {
            $scope->clear();
        });
    }
}
