<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The creation wizard's Structure step may no longer change the event's rounds (GetEventWizardStructureLock): a round got
 * an entry, a linked result or a stopwatch run after the page was loaded - checked again under the event's participants
 * lock (SetUpEventWizardStructureHandler), nothing changed.
 */
final class EventWizardStructureLocked extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('The event\'s rounds have entries, results or a stopwatch run - the structure is not changed in the wizard.');
    }
}
