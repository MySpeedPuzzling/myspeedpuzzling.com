<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Api;

use SpeedPuzzling\Web\Api\V1\PlayerSolvesResponse;
use SpeedPuzzling\Web\Api\V1\PuzzleDifficultyResponse;
use SpeedPuzzling\Web\Api\V1\PuzzleStatisticsResponse;
use SpeedPuzzling\Web\Api\V1\TimePredictionResponse;
use SpeedPuzzling\Web\Results\PlayerPuzzleSolves;
use SpeedPuzzling\Web\Results\PuzzleDifficultyResult;
use SpeedPuzzling\Web\Results\PuzzleStatisticsResult;
use SpeedPuzzling\Web\Results\TimePredictionResult;

/**
 * The per-puzzle insight objects of one API list, loaded in batch by
 * PuzzleResponseFactory::insightsFor() and already gated for the calling
 * token: an object type the token is not entitled to is null for every
 * puzzle of the list; an entitled object is always present, synthesising
 * "no data" (zeros, nulls, "insufficient") for puzzles the batch queries did
 * not return.
 */
final readonly class PuzzleInsightsBatch
{
    /**
     * @param array<string, PuzzleStatisticsResult> $statistics keyed by puzzle id; absent = never solved
     * @param null|array<string, PuzzleDifficultyResult> $difficulties null = not entitled; absent id = no row yet
     * @param null|array<string, TimePredictionResult> $predictions null = not entitled; absent id = nothing to predict from
     * @param null|array<string, PlayerPuzzleSolves> $solves null = not entitled; absent id = never solved
     */
    public function __construct(
        private array $statistics,
        private null|array $difficulties,
        private null|array $predictions,
        private null|array $solves,
    ) {
    }

    public static function empty(): self
    {
        return new self(statistics: [], difficulties: null, predictions: null, solves: null);
    }

    public function statistics(string $puzzleId): PuzzleStatisticsResponse
    {
        return PuzzleStatisticsResponse::fromResult($this->statistics[$puzzleId] ?? PuzzleStatisticsResult::empty($puzzleId));
    }

    public function difficulty(string $puzzleId): null|PuzzleDifficultyResponse
    {
        if ($this->difficulties === null) {
            return null;
        }

        return isset($this->difficulties[$puzzleId])
            ? PuzzleDifficultyResponse::fromResult($this->difficulties[$puzzleId])
            : PuzzleDifficultyResponse::insufficient();
    }

    public function prediction(string $puzzleId): null|TimePredictionResponse
    {
        if ($this->predictions === null) {
            return null;
        }

        return TimePredictionResponse::fromResult($this->predictions[$puzzleId] ?? null);
    }

    public function solves(string $puzzleId): null|PlayerSolvesResponse
    {
        if ($this->solves === null) {
            return null;
        }

        return PlayerSolvesResponse::fromResult($this->solves[$puzzleId] ?? PlayerPuzzleSolves::empty($puzzleId));
    }
}
