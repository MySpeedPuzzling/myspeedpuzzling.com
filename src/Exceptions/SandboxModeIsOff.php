<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * A sandbox-only command or handler ran on an installation without SANDBOX=1 (docs/features/sandbox/README.md).
 */
final class SandboxModeIsOff extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This runs only on the sandbox (SANDBOX=1) - refused, nothing was written.');
    }
}
