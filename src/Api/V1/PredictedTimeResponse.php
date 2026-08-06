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
            openapi: new OpenApiOperation(tags: ['My Results & Solving Times']),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_RESULTS:READ')",
            provider: MyPredictedTimeResponseProvider::class,
        ),
        new Get(
            uriTemplate: '/v1/players/{playerId}/puzzles/{puzzleId}/predicted-time',
            openapi: new OpenApiOperation(tags: ['Players']),
            security: "is_granted('ROLE_OAUTH2_RESULTS:READ')",
            provider: PlayerPredictedTimeResponseProvider::class,
        ),
    ],
)]
final class PredictedTimeResponse
{
    public function __construct(
        public string $puzzle_id,
        public null|int $predicted_seconds,
        public null|int $range_low_seconds,
        public null|int $range_high_seconds,
        public bool $is_personalized,
        public null|int $personal_solve_count,
        public null|int $last_time_seconds,
        public null|float $difficulty_score,
        public null|string $difficulty_level,
        public null|string $difficulty_confidence,
    ) {
    }

    /**
     * Not a member, opted out, or not enough data to predict from.
     */
    public static function empty(string $puzzleId): self
    {
        return new self(
            puzzle_id: $puzzleId,
            predicted_seconds: null,
            range_low_seconds: null,
            range_high_seconds: null,
            is_personalized: false,
            personal_solve_count: null,
            last_time_seconds: null,
            difficulty_score: null,
            difficulty_level: null,
            difficulty_confidence: null,
        );
    }
}
