<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An uploaded participant list that is not (or no longer) kept for this event: an unknown token, another event's
 * token, expired, discarded or already imported (ParticipantImportStash).
 */
final class StashedParticipantImportNotFound extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('The uploaded participant list is not kept for this event.');
    }
}
