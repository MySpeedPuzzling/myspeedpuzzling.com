<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use SpeedPuzzling\Web\Results\PuzzleStatisticsResult;

/**
 * Community statistics of a puzzle - public, always split by discipline (solo,
 * duo and team are different disciplines and are never merged); solved_times
 * is the total across them.
 */
final class PuzzleStatisticsResponse
{
    public function __construct(
        public int $solvedTimes,
        public PuzzleStatisticsGroupResponse $solo,
        public PuzzleStatisticsGroupResponse $duo,
        public PuzzleStatisticsGroupResponse $team,
    ) {
    }

    public static function fromResult(PuzzleStatisticsResult $statistics): self
    {
        return new self(
            solvedTimes: $statistics->solvedTimes,
            solo: PuzzleStatisticsGroupResponse::fromResult($statistics->solo),
            duo: PuzzleStatisticsGroupResponse::fromResult($statistics->duo),
            team: PuzzleStatisticsGroupResponse::fromResult($statistics->team),
        );
    }
}
