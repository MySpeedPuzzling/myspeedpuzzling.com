<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Shows the round's official results on its public round page. The first publish of a round tells the players with a
 * finished result (OfficialRoundResultsPublished) - never again for the round. `competitionId` is the competition the
 * caller authorised; a round of another one is refused.
 */
readonly final class PublishRoundResults
{
    public function __construct(
        public string $competitionId,
        public string $roundId,
    ) {
    }
}
