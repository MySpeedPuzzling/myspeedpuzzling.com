<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\PlayerRanking;
use SpeedPuzzling\Web\Value\Comparison;

readonly final class PlayersComparison
{
    /**
     * @param array<string, PlayerRanking> $playerRanking
     * @param array<string, PlayerRanking> $opponentRanking
     * @return array<Comparison>
     */
    public function compare(array $playerRanking, array $opponentRanking): array
    {
        // Extract puzzle IDs
        $puzzleIdsFirstArray = array_map(
            static fn(PlayerRanking $playerRanking): string => $playerRanking->puzzleId,
            $playerRanking,
        );

        $puzzleIdsSecondArray = array_map(
            static fn(PlayerRanking $playerRanking): string => $playerRanking->puzzleId,
            $opponentRanking,
        );

        // Both puzzlers solved these puzzles (ids...)
        $commonPuzzleIds = array_intersect($puzzleIdsFirstArray, $puzzleIdsSecondArray);

        $compareResult = [];

        foreach ($commonPuzzleIds as $commonPuzzleId) {
            $compareResult[] = new Comparison(
                playerTime: $playerRanking[$commonPuzzleId]->time,
                opponentTime: $opponentRanking[$commonPuzzleId]->time,
                puzzleId: $playerRanking[$commonPuzzleId]->puzzleId,
                puzzleName: $playerRanking[$commonPuzzleId]->puzzleName,
                puzzleAlternativeName: $playerRanking[$commonPuzzleId]->puzzleAlternativeName,
                manufacturerName: $playerRanking[$commonPuzzleId]->manufacturerName,
                piecesCount: $playerRanking[$commonPuzzleId]->piecesCount,
                puzzleImage: $playerRanking[$commonPuzzleId]->puzzleImage,
            );
        }

        return $compareResult;
    }
}
