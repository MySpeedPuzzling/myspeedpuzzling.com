<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;

#[ApiResource(
    shortName: 'PredictedTime',
    operations: [
        new Get(
            uriTemplate: '/v1/me/puzzles/{puzzleId}/predicted-time',
            openapi: new OpenApiOperation(
                tags: ['My Results & Solving Times'],
                summary: 'Predicted solving time and difficulty of a puzzle for the token owner',
                description: 'Puzzle Insights are members-only, exactly as on the website: without an active membership every field except puzzle_id is null. '
                    . 'The prediction additionally honours the owner\'s "time predictions" opt-out and is null when there is not enough data to predict from; '
                    . 'the difficulty fields depend only on the membership. '
                    . 'difficulty_level is one of very_easy, easy, average, challenging, hard, very_hard; '
                    . 'difficulty_confidence is one of insufficient, low, medium, high.',
            ),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_RESULTS:READ')",
            provider: MyPredictedTimeResponseProvider::class,
        ),
    ],
)]
final class PredictedTimeResponse
{
    public function __construct(
        public string $puzzleId,
        public null|int $predictedSeconds,
        public null|int $rangeLowSeconds,
        public null|int $rangeHighSeconds,
        public bool $isPersonalized,
        public null|int $personalSolveCount,
        public null|int $lastTimeSeconds,
        public null|float $difficultyScore,
        public null|string $difficultyLevel,
        public null|string $difficultyConfidence,
    ) {
    }

    /**
     * Not a member: Puzzle Insights are members-only on the website, so the API
     * returns the same nothing (see docs/features/api/README.md, Members-Exclusive Data).
     */
    public static function membersOnly(string $puzzleId): self
    {
        return new self(
            puzzleId: $puzzleId,
            predictedSeconds: null,
            rangeLowSeconds: null,
            rangeHighSeconds: null,
            isPersonalized: false,
            personalSolveCount: null,
            lastTimeSeconds: null,
            difficultyScore: null,
            difficultyLevel: null,
            difficultyConfidence: null,
        );
    }

    /**
     * The flat projection of the insight objects every puzzle endpoint carries
     * (GET /v1/puzzles, GET /v1/puzzles/{id}): the same TimePredictionResponse and
     * PuzzleDifficultyResponse, flattened - no sample_size, no attempt number,
     * and a puzzle without a difficulty row is null here (not "insufficient"),
     * exactly as this endpoint has always answered.
     */
    public static function fromInsights(
        string $puzzleId,
        TimePredictionResponse $prediction,
        null|PuzzleDifficultyResponse $difficulty,
    ): self {
        return new self(
            puzzleId: $puzzleId,
            predictedSeconds: $prediction->predictedSeconds,
            rangeLowSeconds: $prediction->rangeLowSeconds,
            rangeHighSeconds: $prediction->rangeHighSeconds,
            isPersonalized: $prediction->isPersonalized,
            personalSolveCount: $prediction->personalSolveCount,
            lastTimeSeconds: $prediction->lastTimeSeconds,
            difficultyScore: $difficulty?->score,
            difficultyLevel: $difficulty?->level,
            difficultyConfidence: $difficulty?->confidence,
        );
    }
}
