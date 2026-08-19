<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

final class SolvingTimeResponse
{
    public function __construct(
        public string $timeId,
        public string $puzzleId,
        public null|int $timeSeconds,
        public null|string $finishedAt,
        public bool $firstAttempt,
        public bool $unboxed,
        public null|string $comment,
        public null|string $roundId = null,
        /**
         * POST only: the time prediction that applied *before* this solve (the one the
         * added-time recap page shows) - solo times, token owner a member who has not
         * opted out, PAT or results:read. Null otherwise, and always null on PUT.
         */
        public null|TimePredictionResponse $prediction = null,
    ) {
    }
}
