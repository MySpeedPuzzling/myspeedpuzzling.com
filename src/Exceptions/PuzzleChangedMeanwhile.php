<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The puzzle's record changed after the form was loaded (PuzzleRecordVersion) - nothing is saved; the form comes back
 * with the message `puzzle_names.record_changed_meanwhile`.
 *
 * An HTTP exception (422), not `#[WithHttpStatus]`: UnwrapHttpExceptionMiddleware hands only those to the controller as
 * themselves, so a form can catch it and an API caller gets a 422 instead of a 500.
 */
final class PuzzleChangedMeanwhile extends UnprocessableEntityHttpException
{
    public function __construct()
    {
        parent::__construct('This puzzle was changed while you were editing it. Reload to see the current state.');
    }
}
