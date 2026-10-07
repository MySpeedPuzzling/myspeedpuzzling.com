<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The event changed since the organiser saw the import preview (another import, an edit, a result of somebody full
 * sync would have removed) - nothing was written; the preview has to be checked again
 * (docs/features/competitions-management/participant-import-preview.md D8).
 * An HTTP exception, so UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class ParticipantImportPreviewStale extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('The participant import preview is out of date.');
    }
}
