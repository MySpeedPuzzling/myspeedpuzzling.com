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
    /**
     * @param list<RoundChallengeTime> $challengeTimes
     */
    public function __construct(
        public string $roundId,
        public StandingScoreStatus $status,
        // null when the entrant is not in the round or it does not count
        public null|RoundEntryResult $result = null,
        // The seconds the round adds to the total - null = no score
        public null|int $scoreSeconds = null,
        // The official place in the round
        public null|int $rank = null,
        // Competition toolkit T6 - the score's parts: the time (finished seconds, or the limit of an unfinished result),
        // the pieces missing (not placed at the time's up, or missing from a finished puzzle) and the penalty seconds
        // they cost; scoreSeconds = timeSeconds + penaltySeconds
        public null|int $timeSeconds = null,
        public int $missingPieces = 0,
        public int $penaltySeconds = 0,
        // A solve-all block's challenge times in solving order (real events W4) - pick rounds of K ≥ 2 with splits only
        public array $challengeTimes = [],
    ) {
    }

    /**
     * A finished puzzle that missed pieces (a piece missing from the factory) - shown next to the time
     */
    public function isFinishedWithMissingPieces(): bool
    {
        return $this->status === StandingScoreStatus::Finished && $this->missingPieces > 0;
    }

    /**
     * `challengeTimes` only when there are some - every other round's JSON stays as it was.
     *
     * @return array{roundId: string, status: string, result: null|array<string, int|true>, scoreSeconds: null|int, rank: null|int, timeSeconds: null|int, missingPieces: int, penaltySeconds: int, challengeTimes?: list<array{label: string, seconds: int}>}
     */
    public function toArray(): array
    {
        $array = [
            'roundId' => $this->roundId,
            'status' => $this->status->value,
            'result' => $this->result?->toWire(),
            'scoreSeconds' => $this->scoreSeconds,
            'rank' => $this->rank,
            'timeSeconds' => $this->timeSeconds,
            'missingPieces' => $this->missingPieces,
            'penaltySeconds' => $this->penaltySeconds,
        ];

        if ($this->challengeTimes !== []) {
            $array['challengeTimes'] = array_map(static fn (RoundChallengeTime $time): array => $time->toArray(), $this->challengeTimes);
        }

        return $array;
    }
}
