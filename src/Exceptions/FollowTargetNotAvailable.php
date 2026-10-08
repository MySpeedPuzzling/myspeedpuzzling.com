<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What a star asked to follow cannot be followed: unknown, an edition (its series is followed instead), not approved
 * or rejected. One answer for all of them.
 */
final class FollowTargetNotAvailable extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('Follow target not available');
    }
}
