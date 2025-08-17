<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\PlayerRanking;

readonly final class FilterPuzzlerRanking
{
    /**
     * @param array<string, PlayerRanking> $playerRankings
     * @return array<string, PlayerRanking>
     */
    public function rankingAboveRank(
        array $playerRankings,
        int $puzzlePieces,
        int $onlyAfterRank,
        null|int $resultsLimit = null
    ): array {
        /** @var array<string, PlayerRanking> $filteredResults */
        $filteredResults = [];

        foreach ($playerRankings as $puzzleId => $ranking) {
            if ($ranking->piecesCount !== $puzzlePieces) {
                unset($playerRankings[$puzzleId]);
                continue;
            }

            if ($ranking->rank <= $onlyAfterRank) {
                unset($playerRankings[$puzzleId]);
            }

            $filteredResults[$puzzleId] = $ranking;

            if ($resultsLimit !== null && count($filteredResults) >= $resultsLimit) {
                break;
            }
        }

        return $filteredResults;
    }
}
