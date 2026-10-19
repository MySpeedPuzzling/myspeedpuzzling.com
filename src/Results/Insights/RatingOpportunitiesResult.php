<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use SpeedPuzzling\Web\Value\RatingStanding;

/**
 * "Where can I gain MSP rating?" for one rated piece count. Every rating number is in ladder points (rating × 1000).
 */
readonly final class RatingOpportunitiesResult
{
    /**
     * @param list<RatingMove> $moves the MOVES_SHOWN biggest gains, biggest first
     * @param list<RatingMove> $movesByGainPerHour the MOVES_SHOWN best gains per hour, best first
     * @param list<PendingQualification> $pending biggest gain first
     * @param list<RatingEntryView> $firstAttemptEntries best first (the strip)
     * @param list<RatingEntryView> $bestTimeEntries best first
     */
    public function __construct(
        public int $piecesCount,
        public RatingStanding $standing,
        /** The rating the entries make (= player_elo while on the ladder) */
        public float $rating,
        /** Place on today's ladder (on the ladder only) and the ladder's size */
        public null|int $place,
        public int $ladderSize,
        public float $firstAttemptAverage,
        public float $bestTimeAverage,
        public int $entriesLeavingWithin60Days,
        /** Every move found (the lists hold the best of them) */
        public int $movesCount,
        public array $moves,
        public array $movesByGainPerHour,
        public array $pending,
        /** What the best 5 / 10 moves add together (not the sum of their gains: they share the best 100) */
        public float $bestFiveGain,
        public float $bestTenGain,
        /** The place the best 10 moves would give on today's ladder (on the ladder only) */
        public null|int $placeAfterBestTen,
        public null|LadderPath $ladderPath,
        public array $firstAttemptEntries,
        public array $bestTimeEntries,
    ) {
    }

    public static function empty(int $piecesCount, RatingStanding $standing): self
    {
        return new self($piecesCount, $standing, 0.0, null, 0, 0.0, 0.0, 0, 0, [], [], [], 0.0, 0.0, null, null, [], []);
    }
}
