<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Value\Statistics\OverallStatistics;
use SpeedPuzzling\Web\Value\Statistics\PerCategoryStatistics;
use SpeedPuzzling\Web\Value\Statistics\PiecesStatistics;
use SpeedPuzzling\Web\Value\Statistics\SolvedPuzzleStatistics;
use SpeedPuzzling\Web\Value\Statistics\TimeSpentSolvingStatistics;

readonly final class ComputeStatistics
{
    public function __construct(
        private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
    ) {
    }

    /**
     * @return array{OverallStatistics, PerCategoryStatistics, PerCategoryStatistics, PerCategoryStatistics}
     */
    public function forPlayer(
        string $playerId,
        DateTimeImmutable $dateFrom,
        DateTimeImmutable $dateTo,
    ): array {
        $soloResults = $this->getPlayerSolvedPuzzles->soloByPlayerId($playerId, $dateFrom, $dateTo);
        $duoResults = $this->getPlayerSolvedPuzzles->duoByPlayerId($playerId, $dateFrom, $dateTo);
        $teamResults = $this->getPlayerSolvedPuzzles->teamByPlayerId($playerId, $dateFrom, $dateTo);

        $soloStatistics = new PerCategoryStatistics($soloResults);
        $duoStatistics = new PerCategoryStatistics($duoResults);
        $teamStatistics = new PerCategoryStatistics($teamResults);
        $overallStatistics = new OverallStatistics($soloStatistics, $duoStatistics, $teamStatistics);

        return [
            $overallStatistics,
            $soloStatistics,
            $duoStatistics,
            $teamStatistics,
        ];
    }
}
