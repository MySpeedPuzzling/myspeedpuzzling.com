<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * A round's published official results may have somebody new to tell - recorded on every publish
 * (CompetitionRound::publishResults()), when a finished result is recorded on a published round
 * (HasOfficialResult::recordResult()) and dispatched for the published rounds of an event that gets approved
 * (ApproveCompetitionHandler, ApproveCompetitionSeriesHandler). NotifyWhenOfficialRoundResultsPublished tells every
 * player linked to an entry with a finished result who was not told yet - each player once per round, ever.
 * Asynchronous.
 */
readonly final class OfficialRoundResultsPublished
{
    public function __construct(
        public UuidInterface $roundId,
    ) {
    }
}
