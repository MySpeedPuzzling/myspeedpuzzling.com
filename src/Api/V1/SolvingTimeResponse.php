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
        // The event link as stored after the save (docs/features/events-page/high-frequency-series.md "API v1"): the
        // round the time is in, the one-time event or edition it is linked to (picked, or the edition MySpeedPuzzling
        // found for a series), and the series - the series picked, else the series of the linked edition
        public null|string $roundId = null,
        public null|string $competitionId = null,
        public null|string $seriesId = null,
        /**
         * POST only: the time prediction that applied *before* this solve (the one the
         * added-time recap page shows) - solo times, token owner a member who has not
         * opted out, PAT or results:read. Null otherwise, and always null on PUT.
         */
        public null|TimePredictionResponse $prediction = null,
    ) {
    }
}
