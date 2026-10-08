<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A participants sheet change set id the server took before for another event (ParticipantSheetChangeReceipt) - never
 * answered with that event's outcomes, never applied. The page sends its changes again under a new id. Nothing changed.
 */
final class SheetChangesetIdTaken extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('This change set id belongs to a change set of another event.');
    }
}
