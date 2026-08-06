<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;

final readonly class PredictedTimeResponseFactory
{
    public function __construct(
        private GetPlayerProfile $getPlayerProfile,
        private GetPuzzleOverview $getPuzzleOverview,
        private GetPlayerPrediction $getPlayerPrediction,
        private GetPuzzleDifficulty $getPuzzleDifficulty,
    ) {
    }

    public function build(
        string $targetPlayerId,
        string $puzzleId,
        bool $requesterHasActiveMembership,
        bool $requesterIsTarget,
    ): PredictedTimeResponse {
        // Throws PuzzleNotFound for a missing/invalid id.
        $this->getPuzzleOverview->byId($puzzleId);

        // Throws PlayerNotFound for a missing/invalid id.
        $targetProfile = $this->getPlayerProfile->byId($targetPlayerId);

        $eligible = $requesterHasActiveMembership
            && $targetProfile->timePredictionsOptedOut === false
            && ($requesterIsTarget || $targetProfile->isPrivate === false);

        if ($eligible === false) {
            return PredictedTimeResponse::empty($puzzleId);
        }

        $prediction = $this->getPlayerPrediction->forPuzzle($targetPlayerId, $puzzleId);

        if ($prediction === null) {
            return PredictedTimeResponse::empty($puzzleId);
        }

        $difficultyScore = null;
        $difficultyLevel = null;
        $difficultyConfidence = null;

        $difficulty = $this->getPuzzleDifficulty->byPuzzleId($puzzleId);

        if ($difficulty !== null) {
            $difficultyScore = $difficulty->difficultyScore;
            $difficultyLevel = $difficulty->difficultyTier?->name;
            $difficultyConfidence = $difficulty->confidence->value;
        }

        return new PredictedTimeResponse(
            puzzle_id: $puzzleId,
            predicted_seconds: $prediction->predictedSeconds,
            range_low_seconds: $prediction->rangeLowSeconds,
            range_high_seconds: $prediction->rangeHighSeconds,
            is_personalized: $prediction->isPersonalized,
            personal_solve_count: $prediction->personalSolveCount,
            last_time_seconds: $prediction->lastTimeSeconds,
            difficulty_score: $difficultyScore,
            difficulty_level: $difficultyLevel,
            difficulty_confidence: $difficultyConfidence,
        );
    }
}
