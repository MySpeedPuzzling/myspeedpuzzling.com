<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Exception;

/**
 * The ≥1-sign-in-method invariant (settled, D13): an account must always keep
 * `password IS NOT NULL OR ≥1 oauth_identity` - otherwise nobody can ever sign
 * in to it again (Apple private-relay addresses can even die after unlink).
 */
final class CannotUnlinkLastSignInMethod extends Exception
{
    public function __construct()
    {
        parent::__construct('Set a password before disconnecting the last sign-in method');
    }
}
