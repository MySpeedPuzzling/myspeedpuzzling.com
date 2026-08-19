<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One puzzle card of the picker: puzzle basics, community numbers and the
 * viewer's own history on it. The "my*" fields are zero/null for guests.
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
    ) {
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
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
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
        );
    }
}
