<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * A round's official results were published for the first time (CompetitionRound::publishResults()) - the players
 * linked to an entry with a finished result get an in-app notification (NotifyWhenOfficialRoundResultsPublished).
 * Asynchronous, recorded once per round.
 */
readonly final class OfficialRoundResultsPublished
{
    public function __construct(
        public UuidInterface $roundId,
    ) {
    }
}
