<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Exception;

final class CurrentPasswordDoesNotMatch extends Exception
{
    public function __construct()
    {
        parent::__construct('The current password does not match');
    }
}
