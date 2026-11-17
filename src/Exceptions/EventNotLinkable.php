<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * "Link my results" to an event or series that is not publicly visible and that the player may not edit (somebody
 * else's draft or pending event), or one that was rejected - nothing is linked.
 */
final class EventNotLinkable extends AccessDeniedHttpException
{
}
