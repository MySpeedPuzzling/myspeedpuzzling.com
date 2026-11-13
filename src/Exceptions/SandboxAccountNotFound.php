<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * myspeedpuzzling:sandbox:export-account found no account with that e-mail (docs/features/sandbox/README.md).
 */
final class SandboxAccountNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No account with that e-mail - nothing exported.');
    }
}
