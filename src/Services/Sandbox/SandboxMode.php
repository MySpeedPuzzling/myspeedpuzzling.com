<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sandbox;

use SpeedPuzzling\Web\Exceptions\SandboxModeIsOff;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * This installation is the sandbox (sandbox.myspeedpuzzling.com, docs/features/sandbox/README.md): test data only, a
 * banner on every page, noindex on every answer, a membership for everybody. Env `SANDBOX` - 0 everywhere else.
 *
 * The sandbox-only commands and handlers call assertOn() before they write anything, so a slip on production (a cron
 * line copied into the wrong file, a command typed into the wrong shell) refuses instead of inventing players there.
 */
final readonly class SandboxMode
{
    public function __construct(
        #[Autowire(param: 'sandbox')]
        public bool $on,
    ) {
    }

    /**
     * @throws SandboxModeIsOff
     */
    public function assertOn(): void
    {
        if ($this->on === false) {
            throw new SandboxModeIsOff();
        }
    }
}
