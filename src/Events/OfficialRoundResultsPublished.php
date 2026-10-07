<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * A round's published official results may have somebody new to tell - recorded on every publish
 * (CompetitionRound::publishResults()), dispatched once per change set that records a finished result on a published
 * round (RecordRoundResultsHandler - one run checks every entry, however many results the set records) and for the
 * published rounds of an event that gets approved
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
