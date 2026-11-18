<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\StandingEntrant;

/**
 * One entrant of a computed standing (StandingCalculator::sum() / stages()).
 *
 * A `stages` row (competition toolkit v2 S5) carries the stage it reached: `stage` (1 = the first stage), `stageName`
 * (its rounds' names), `stageRoundId` + `stageRank` (its round there and its place in it) - or, with
 * `qualifiedForStage`, the next stage it qualified for while that stage is not classified yet (no round, no place).
 * Its `perRound` holds that one round only. A `sum` row has none of them (null / false).
 */
readonly final class StandingRow
{
    /**
     * @param list<string> $countries
     * @param list<StandingEntrant> $entrants
     * @param array<string, StandingRoundScore> $perRound every round of the standing, in its order
     */
    public function __construct(
        public string $key,
        // null = not ranked (incomplete: a counted round without a score)
        public null|int $rank,
        public bool $complete,
        public string $name,
        public array $countries,
        public array $entrants,
        public int $totalSeconds,
        public int $roundsScored,
        public int $roundsFinished,
        public array $perRound,
        // Competition toolkit T6: totalSeconds = totalTimeSeconds + totalPenaltySeconds; the pieces missing over all rounds
        // (penalised or not)
        public int $totalTimeSeconds = 0,
        public int $totalMissingPieces = 0,
        public int $totalPenaltySeconds = 0,
        public null|int $stage = null,
        public null|string $stageName = null,
        public null|string $stageRoundId = null,
        public null|int $stageRank = null,
        public bool $qualifiedForStage = false,
    ) {
    }

    public function isStaged(): bool
    {
        return $this->stage !== null;
    }

    /**
     * The stage fields only on a `stages` row - a `sum` row's JSON stays as it was.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
            'key' => $this->key,
            'rank' => $this->rank,
            'complete' => $this->complete,
            'name' => $this->name,
            'countries' => $this->countries,
            'entrants' => array_map(static fn (StandingEntrant $entrant): array => $entrant->toArray(), $this->entrants),
            'totalSeconds' => $this->totalSeconds,
            'totalTimeSeconds' => $this->totalTimeSeconds,
            'totalMissingPieces' => $this->totalMissingPieces,
            'totalPenaltySeconds' => $this->totalPenaltySeconds,
            'roundsScored' => $this->roundsScored,
            'roundsFinished' => $this->roundsFinished,
            'perRound' => array_values(array_map(static fn (StandingRoundScore $score): array => $score->toArray(), $this->perRound)),
        ];

        if ($this->stage !== null) {
            $array['stage'] = $this->stage;
            $array['stageName'] = $this->stageName;
            $array['stageRoundId'] = $this->stageRoundId;
            $array['stageRank'] = $this->stageRank;
            $array['qualifiedForStage'] = $this->qualifiedForStage;
        }

        return $array;
    }
}
