<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

/**
 * One solving time of a result list, with the solved puzzle's community
 * statistics (public, always an object) and difficulty (members-only: null
 * when the token owner is not a member or the token is a machine token - see
 * docs/features/api/README.md, Members-Exclusive Data).
 *
 * A duo/team result also names the pair/team it belongs to: `team_id` identifies the exact set of people
 * (same people = same id, in anybody's results), `team_name` is the name its members gave it, if any.
 * Both are null for a solo result. Additive fields - nothing else about the response changed.
 */
final class PlayerResultResponse
{
    public function __construct(
        public string $timeId,
        public string $puzzleId,
        public string $puzzleName,
        public string $manufacturerName,
        public int $piecesCount,
        public null|int $timeSeconds,
        public null|string $finishedAt,
        public bool $firstAttempt,
        public null|string $puzzleImage,
        public null|string $comment,
        public PuzzleStatisticsResponse $statistics,
        public null|PuzzleDifficultyResponse $difficulty = null,
        public null|string $teamId = null,
        public null|string $teamName = null,
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
        /**
         * A relay team's result (docs/features/event-results-import/solo-and-relays.md §2): the team solved all the
         * relay's puzzles at the same time and only the total is known - `time_seconds` is then null and it is no relax
         * solve: a null time is a relax solve only while `pieces_placed` and `relay_total_seconds` are null too. The
         * relay's total in seconds, null on every other result. Additive (2026-11).
         */
        public null|int $relayTotalSeconds = null,
    ) {
    }
}
