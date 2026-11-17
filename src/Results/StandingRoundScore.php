<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\StandingScoreStatus;

/**
 * What one round gives one entrant of a standing.
 */
readonly final class StandingRoundScore
{
    public function __construct(
        public string $roundId,
        public StandingScoreStatus $status,
        // null when the entrant is not in the round or it does not count
        public null|RoundEntryResult $result = null,
        // The seconds the round adds to the total - null = no score
        public null|int $scoreSeconds = null,
        // The official place in the round
        public null|int $rank = null,
    ) {
    }

    /**
     * @return array{roundId: string, status: string, result: null|array<string, int|true>, scoreSeconds: null|int, rank: null|int}
     */
    public function toArray(): array
    {
        return [
            'roundId' => $this->roundId,
            'status' => $this->status->value,
            'result' => $this->result?->toWire(),
            'scoreSeconds' => $this->scoreSeconds,
            'rank' => $this->rank,
        ];
    }
}
