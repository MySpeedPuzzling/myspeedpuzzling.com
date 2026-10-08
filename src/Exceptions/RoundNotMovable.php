<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\RoundNotMovableReason;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * MoveRoundToCompetition refused - nothing changed (docs/features/organizations/README.md "Restructuring tools").
 */
final class RoundNotMovable extends ConflictHttpException
{
    public function __construct(
        readonly public RoundNotMovableReason $reason,
    ) {
        parent::__construct(match ($reason) {
            RoundNotMovableReason::SameCompetition => 'The round is in this competition already. Nothing was changed.',
            RoundNotMovableReason::HasEntries => 'Participants are entered in the round (round entries or pairs/teams) - they belong to its competition and cannot move with the round yet. Nothing was changed.',
            RoundNotMovableReason::StopwatchRunning => 'The round\'s stopwatch is running - stop it first. Nothing was changed.',
            RoundNotMovableReason::DraftTargetWithResults => 'The target is a draft (or an edition of a draft series) and the round has solving times - a draft never gets results. Publish the target first, or move an empty round. Nothing was changed.',
        });
    }
}
