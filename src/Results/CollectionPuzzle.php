<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class CollectionPuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public int $piecesCount,
        public null|string $puzzleImage,
        public null|string $ean,
        public null|string $identificationNumber,
        public null|string $manufacturerName,
        public null|string $itemId,
        public DateTimeImmutable $addedAt,
        public null|string $comment,
        public null|string $price,
        public null|string $condition,
        public int $timesSolved,
    ) {
    }

    /**
     * @param array{
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_alternative_name: null|string,
     *     pieces_count: int,
     *     puzzle_image: null|string,
     *     ean: null|string,
     *     identification_number: null|string,
     *     manufacturer_name: null|string,
     *     item_id: null|string,
     *     added_at: string,
     *     comment: null|string,
     *     price: null|string,
     *     condition: null|string,
     *     times_solved: int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeName: $row['puzzle_alternative_name'],
            piecesCount: $row['pieces_count'],
            puzzleImage: $row['puzzle_image'],
            ean: $row['ean'],
            identificationNumber: $row['identification_number'],
            manufacturerName: $row['manufacturer_name'],
            itemId: $row['item_id'],
            addedAt: new DateTimeImmutable($row['added_at']),
            comment: $row['comment'],
            price: $row['price'],
            condition: $row['condition'],
            timesSolved: $row['times_solved'],
        );
    }

    public function getFullName(): string
    {
        $name = $this->puzzleName;
        if ($this->puzzleAlternativeName !== null) {
            $name .= ' - ' . $this->puzzleAlternativeName;
        }
        return $name;
    }

    public function hasForSaleInfo(): bool
    {
        return $this->price !== null || $this->condition !== null;
    }

    public function getPriceFormatted(): string
    {
        if ($this->price === null) {
            return '';
        }
        return number_format((float) $this->price, 2);
    }
}
