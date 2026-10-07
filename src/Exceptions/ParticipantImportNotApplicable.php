<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The plan cannot be applied: it has errors, or full sync was asked for while it is refused (design doc D7b) -
 * the preview shows why. Nothing was written.
 * An HTTP exception, so UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class ParticipantImportNotApplicable extends UnprocessableEntityHttpException
{
    public function __construct()
    {
        parent::__construct('The participant import cannot be applied.');
    }
}
