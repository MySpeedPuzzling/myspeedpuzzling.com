<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UserAccountNotFound extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('User account not found');
    }
}
