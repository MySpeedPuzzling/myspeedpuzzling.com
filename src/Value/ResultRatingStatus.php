<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingFormula;

/**
 * Whether one result counts toward the player's MSP rating, and if not why - the results export's `msp_rating` /
 * `msp_rating_reason` columns (docs/features/data-export.md). Read from what the rating batch stored
 * (player_rating_entry, puzzle_rating_times), never computed again: the export says what the rating did.
 *
 * "Counts" = the result is an entry of a portfolio among the player's best 100 (MspRatingFormula::CAP). A player who is
 * not rated yet has entries too - those are the first attempts and best times that will make the rating, so a
 * first-attempt entry is also one of the 20 the qualification asks for.
 */
readonly final class ResultRatingStatus
{
    public const string FIRST_ATTEMPT = 'first_attempt';
    public const string BEST_TIME = 'best_time';
    public const string FIRST_ATTEMPT_AND_BEST_TIME = 'first_attempt_and_best_time';
    public const string NOT_COUNTED = 'not_counted';

    public const string REASON_NOT_RATED_PIECE_COUNT = 'not_500_pieces';
    public const string REASON_NOT_SOLO = 'not_solo';
    public const string REASON_NO_TIME = 'no_time';
    public const string REASON_NEEDS_VERIFICATION = 'needs_verification';
    public const string REASON_PRIVATE_PROFILE = 'private_profile';
    public const string REASON_TOO_FEW_SOLVERS = 'puzzle_has_fewer_than_20_first_try_solvers';
    public const string REASON_DIFFICULTY_NOT_SETTLED = 'puzzle_difficulty_not_settled_yet';
    public const string REASON_OLDER_THAN_WINDOW = 'older_than_24_months';
    public const string REASON_NOT_BEST_TIME = 'not_your_best_time_on_this_puzzle';
    public const string REASON_OUTSIDE_TOP = 'outside_your_best_100';
    public const string REASON_NOT_COMPUTED_YET = 'not_computed_yet';

    private function __construct(
        public string $status,
        public null|string $reason,
    ) {
    }

    /**
     * @param null|bool $countsFirstAttempt null = no first-attempt entry is this result, else whether it is among the best 100
     * @param null|bool $countsBestTime null = no best-time entry is this result, else whether it is among the best 100
     */
    public static function of(
        bool $ratedPieceCount,
        bool $solo,
        bool $timed,
        bool $suspicious,
        bool $playerPrivate,
        null|bool $countsFirstAttempt,
        null|bool $countsBestTime,
        bool $puzzleQualifies,
        int $puzzleFirstTrySolvers,
        bool $candidate,
        bool $inWindow,
    ): self {
        $notCounted = static fn (string $reason): self => new self(self::NOT_COUNTED, $reason);

        if ($ratedPieceCount === false) {
            return $notCounted(self::REASON_NOT_RATED_PIECE_COUNT);
        }

        if ($solo === false) {
            return $notCounted(self::REASON_NOT_SOLO);
        }

        if ($timed === false) {
            return $notCounted(self::REASON_NO_TIME);
        }

        if ($suspicious) {
            return $notCounted(self::REASON_NEEDS_VERIFICATION);
        }

        if ($playerPrivate) {
            return $notCounted(self::REASON_PRIVATE_PROFILE);
        }

        if ($countsFirstAttempt === true && $countsBestTime === true) {
            return new self(self::FIRST_ATTEMPT_AND_BEST_TIME, null);
        }

        if ($countsFirstAttempt === true) {
            return new self(self::FIRST_ATTEMPT, null);
        }

        if ($countsBestTime === true) {
            return new self(self::BEST_TIME, null);
        }

        if ($countsFirstAttempt === false || $countsBestTime === false) {
            return $notCounted(self::REASON_OUTSIDE_TOP);
        }

        if ($puzzleQualifies === false) {
            return $notCounted($puzzleFirstTrySolvers < MspRatingFormula::MIN_SOLVERS_PER_PUZZLE ? self::REASON_TOO_FEW_SOLVERS : self::REASON_DIFFICULTY_NOT_SETTLED);
        }

        // The puzzle counts, and this result is neither its first attempt nor its best time
        if ($candidate === false) {
            return $notCounted(self::REASON_NOT_BEST_TIME);
        }

        // It would be an entry: out of the 24 months, or saved after the last run of the batch (every 15 minutes)
        return $notCounted($inWindow ? self::REASON_NOT_COMPUTED_YET : self::REASON_OLDER_THAN_WINDOW);
    }
}
