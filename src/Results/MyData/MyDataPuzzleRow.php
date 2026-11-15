<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\MyData;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\Export\ExportablePuzzle;
use SpeedPuzzling\Web\Results\Export\ExportablePuzzleSolveSummary;

/**
 * One puzzle of the signed-in player's collections - or of their wish list - in "My data" (docs/features/my-data.md).
 */
readonly final class MyDataPuzzleRow
{
    public function __construct(
        // The collection item's id, or the wish list item's
        public string $itemId,
        public ExportablePuzzle $puzzle,
        // The stored path of the puzzle's picture (null while it is hidden) - for the image helpers
        public null|string $imagePath,
        // Null = the main collection (and every wish list row)
        public null|string $collectionId,
        public null|string $collectionName,
        public DateTimeImmutable $addedAt,
        public null|string $comment,
        public bool $lentOut,
        // The player's own results on the puzzle - null = never solved
        public null|ExportablePuzzleSolveSummary $solved,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row, ExportablePuzzle $puzzle, null|ExportablePuzzleSolveSummary $solved): self
    {
        /**
         * @var array{
         *     item_id: string,
         *     puzzle_image: null|string,
         *     collection_id: null|string,
         *     collection_name: null|string,
         *     added_at: string,
         *     comment: null|string,
         *     lent_out: bool,
         * } $row
         */
        return new self(
            itemId: $row['item_id'],
            puzzle: $puzzle,
            imagePath: $row['puzzle_image'],
            collectionId: $row['collection_id'],
            collectionName: $row['collection_name'],
            addedAt: new DateTimeImmutable($row['added_at']),
            comment: $row['comment'] !== null && trim($row['comment']) !== '' ? $row['comment'] : null,
            lentOut: $row['lent_out'],
            solved: $solved,
        );
    }
}
