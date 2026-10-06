<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A puzzle a competition keeps secret (hide_until / hide_image_until in the future) is not approved, merged or edited
 * until it is revealed - a merge would copy its names, codes and picture onto a public puzzle and point the merged
 * page at it, an edit or approval would put it in front of moderators. Nor is it attached unhidden to a round by the
 * internal API - the event page would show it.
 *
 * An HTTP exception, so UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class PuzzleIsStillSecret extends ConflictHttpException
{
    public function __construct(
        readonly public string $puzzleId,
        null|string $message = null,
    ) {
        parent::__construct($message ?? sprintf('Puzzle %s is still secret', $puzzleId));
    }
}
