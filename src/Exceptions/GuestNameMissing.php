<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class GuestNameMissing extends BadRequestHttpException
{
    public function __construct()
    {
        parent::__construct('The player has no name - pass guestName.');
    }
}
