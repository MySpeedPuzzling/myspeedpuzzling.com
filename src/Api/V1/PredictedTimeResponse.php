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
     * Not a member: Puzzle Insights are members-only on the website, so the API
     * returns the same nothing (see docs/features/api/README.md, Members-Exclusive Data).
     */
    public static function membersOnly(string $puzzleId): self
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
