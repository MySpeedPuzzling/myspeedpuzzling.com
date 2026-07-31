<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Exception;

final class OauthIdentityAlreadyLinked extends Exception
{
    public function __construct()
    {
        parent::__construct('This provider identity is already linked to an account');
    }
}
