<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A suggested name the puzzle has already (SuggestPuzzleName) - its main title, or another name that folds equal and
 * already has a language (two names folding equal are one name). Nothing is saved; the form says so.
 *
 * An HTTP exception (422), so UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class PuzzleNameAlreadyKnown extends UnprocessableEntityHttpException
{
    public function __construct()
    {
        parent::__construct('The puzzle already has this name.');
    }
}
