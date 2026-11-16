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
        /**
         * How exactly `finished_at` is known: `day` (every result typed into MySpeedPuzzling or sent through this API),
         * or `month` / `year` for a result imported from a spreadsheet that knew no more - `finished_at` is then the
         * first day of that month or year and must not be read as a day. Additive (2026-11).
         */
        public string $datePrecision = 'day',
        /**
         * An unfinished competition result (docs/features/competitions-management/unfinished-results-plan.md): the pieces
         * placed when the round's time ran out. `time_seconds` is then null - and it is no relax solve: a null time
         * is a relax solve only while `pieces_placed` is null too. Null on every other result. Additive (2026-11).
         */
        public null|int $piecesPlaced = null,
        // An unfinished result's total time when the puzzlers finished it after the time ran out, else null. Additive (2026-11)
        public null|int $finishedLaterSeconds = null,
    ) {
    }
}
