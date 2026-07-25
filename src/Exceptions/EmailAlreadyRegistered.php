<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Exception;

final class EmailAlreadyRegistered extends Exception
{
    public function __construct()
    {
        parent::__construct('This email address already belongs to an account');
    }
}
