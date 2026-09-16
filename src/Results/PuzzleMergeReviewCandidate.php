<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One puzzle put forward as a duplicate, with everything a reviewer needs to
 * judge whether it really is the same product - and what would be lost if it
 * were the one deleted.
 */
readonly final class PuzzleMergeReviewCandidate
{
    public function __construct(
        public string $puzzleId,
        public string $name,
        public null|string $alternativeName,
        public int $piecesCount,
        public null|string $ean,
        public null|string $identificationNumber,
        public null|string $manufacturerId,
        public null|string $manufacturerName,
        public null|string $image,
        public bool $approved,
        public null|string $addedAt,
        // Weight of the record: how much history rides on this puzzle. The heaviest
        // is normally the one that should survive.
        public int $solvedTimesCount,
        public int $collectionItemsCount,
        public int $wishListItemsCount,
        public int $sellSwapItemsCount,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $puzzleId = $row['puzzle_id'];
        assert(is_string($puzzleId));
        $name = $row['name'];
        assert(is_string($name));
        $piecesCount = $row['pieces_count'];
        assert(is_int($piecesCount));
        $approved = $row['approved'];
        assert(is_bool($approved));

        return new self(
            puzzleId: $puzzleId,
            name: $name,
            alternativeName: is_string($row['alternative_name']) ? $row['alternative_name'] : null,
            piecesCount: $piecesCount,
            ean: is_string($row['ean']) ? $row['ean'] : null,
            identificationNumber: is_string($row['identification_number']) ? $row['identification_number'] : null,
            manufacturerId: is_string($row['manufacturer_id']) ? $row['manufacturer_id'] : null,
            manufacturerName: is_string($row['manufacturer_name']) ? $row['manufacturer_name'] : null,
            image: is_string($row['image']) ? $row['image'] : null,
            approved: $approved,
            addedAt: is_string($row['added_at']) ? $row['added_at'] : null,
            solvedTimesCount: self::toCount($row['solved_times_count']),
            collectionItemsCount: self::toCount($row['collection_items_count']),
            wishListItemsCount: self::toCount($row['wish_list_items_count']),
            sellSwapItemsCount: self::toCount($row['sell_swap_items_count']),
        );
    }

    /**
     * Postgres returns COUNT() as bigint, which PDO hands back as a string.
     */
    private static function toCount(mixed $value): int
    {
        assert(is_numeric($value));

        return (int) $value;
    }
}
