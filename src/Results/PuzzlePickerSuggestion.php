<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\MetricConfidence;

/**
 * One puzzle card of the picker: puzzle basics, community numbers and the
 * viewer's own history on it. The "my*" fields are zero/null for guests.
 *
 * Insights fields: difficultyTier / difficultyConfidence are always hydrated
 * (cheap post-LIMIT join; the template decides who may see them);
 * predictedSeconds / gapSeconds are only set when the query had to compute
 * predictions before sampling (gap filter, gap order, personal time budget) —
 * the card itself renders the full TimePredictionResult from GetPlayerPredictions.
 */
readonly final class PuzzlePickerSuggestion
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public null|string $puzzleIdentificationNumber,
        public null|string $puzzleEan,
        public null|string $manufacturerId,
        public null|string $manufacturerName,
        public int $piecesCount,
        public null|string $puzzleImage,
        public null|float $puzzleImageRatio,
        public int $communitySolvedCountSolo,
        public null|int $communityAverageTimeSolo,
        public int $mySolveCountAny,
        public int $mySolveCountSolo,
        public null|int $myFastestSeconds,
        public null|int $myFirstSeconds,
        public null|int $myLatestSeconds,
        public null|DateTimeImmutable $myLastSolvedAt,
        public bool $inMyCollection,
        public bool $isBorrowed,
        public bool $isLentOut,
        public null|DifficultyTier $difficultyTier = null,
        public null|MetricConfidence $difficultyConfidence = null,
        public null|int $predictedSeconds = null,
        public null|int $gapSeconds = null,
    ) {
    }

    /**
     * A difficulty worth showing: a tier backed by enough data.
     */
    public function hasDifficulty(): bool
    {
        return $this->difficultyTier !== null
            && $this->difficultyConfidence !== null
            && $this->difficultyConfidence !== MetricConfidence::Insufficient;
    }

    /**
     * @param array{
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_alternative_name: null|string,
     *     puzzle_identification_number: null|string,
     *     puzzle_ean: null|string,
     *     manufacturer_id: null|string,
     *     manufacturer_name: null|string,
     *     pieces_count: int,
     *     puzzle_image: null|string,
     *     puzzle_image_ratio: null|string|float,
     *     community_solved_count_solo: int|string,
     *     community_average_time_solo: null|int|string,
     *     my_solve_count_any: int|string,
     *     my_solve_count_solo: int|string,
     *     my_fastest_seconds: null|int|string,
     *     my_first_seconds: null|int|string,
     *     my_latest_seconds: null|int|string,
     *     my_last_solved_at: null|string,
     *     in_my_collection: bool,
     *     is_borrowed: bool,
     *     is_lent_out: bool,
     *     difficulty_tier?: null|int|string,
     *     difficulty_confidence?: null|string,
     *     predicted_seconds?: null|int|string,
     *     gap_seconds?: null|int|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $difficultyTier = isset($row['difficulty_tier']) ? DifficultyTier::tryFrom((int) $row['difficulty_tier']) : null;
        $difficultyConfidence = isset($row['difficulty_confidence']) ? MetricConfidence::tryFrom($row['difficulty_confidence']) : null;

        return new self(
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeName: $row['puzzle_alternative_name'],
            puzzleIdentificationNumber: $row['puzzle_identification_number'],
            puzzleEan: $row['puzzle_ean'],
            manufacturerId: $row['manufacturer_id'],
            manufacturerName: $row['manufacturer_name'],
            piecesCount: $row['pieces_count'],
            puzzleImage: $row['puzzle_image'],
            puzzleImageRatio: $row['puzzle_image_ratio'] !== null ? (float) $row['puzzle_image_ratio'] : null,
            communitySolvedCountSolo: (int) $row['community_solved_count_solo'],
            communityAverageTimeSolo: $row['community_average_time_solo'] !== null ? (int) $row['community_average_time_solo'] : null,
            mySolveCountAny: (int) $row['my_solve_count_any'],
            mySolveCountSolo: (int) $row['my_solve_count_solo'],
            myFastestSeconds: $row['my_fastest_seconds'] !== null ? (int) $row['my_fastest_seconds'] : null,
            myFirstSeconds: $row['my_first_seconds'] !== null ? (int) $row['my_first_seconds'] : null,
            myLatestSeconds: $row['my_latest_seconds'] !== null ? (int) $row['my_latest_seconds'] : null,
            myLastSolvedAt: $row['my_last_solved_at'] !== null ? new DateTimeImmutable($row['my_last_solved_at']) : null,
            inMyCollection: $row['in_my_collection'],
            isBorrowed: $row['is_borrowed'],
            isLentOut: $row['is_lent_out'],
            difficultyTier: $difficultyTier,
            difficultyConfidence: $difficultyConfidence,
            predictedSeconds: isset($row['predicted_seconds']) ? (int) $row['predicted_seconds'] : null,
            gapSeconds: isset($row['gap_seconds']) ? (int) $row['gap_seconds'] : null,
        );
    }
}
