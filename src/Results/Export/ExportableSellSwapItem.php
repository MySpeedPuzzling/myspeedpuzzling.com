<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Export;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;

readonly final class ExportableSellSwapItem
{
    public const array COLUMNS = ['sell_swap_item_id', 'added_at', 'listing_type', 'price', 'currency', 'condition', 'comment', 'published_on_marketplace', 'reserved', 'reserved_at', 'reserved_for_name'];

    public function __construct(
        public string $sellSwapItemId,
        public DateTimeImmutable $addedAt,
        public ListingType $listingType,
        public null|float $price,
        public PuzzleCondition $condition,
        public null|string $comment,
        public bool $publishedOnMarketplace,
        public bool $reserved,
        public null|DateTimeImmutable $reservedAt,
        public null|string $reservedForName,
        public null|ExportablePuzzle $puzzle,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row, string $uploadedAssetsBaseUrl): self
    {
        /**
         * @var array{
         *     sell_swap_item_id: string,
         *     added_at: string,
         *     listing_type: string,
         *     price: null|float|string,
         *     condition: string,
         *     comment: null|string,
         *     published_on_marketplace: bool,
         *     reserved: bool,
         *     reserved_at: null|string,
         *     reserved_for_name: null|string,
         * } $row
         */
        return new self(
            sellSwapItemId: $row['sell_swap_item_id'],
            addedAt: new DateTimeImmutable($row['added_at']),
            listingType: ListingType::from($row['listing_type']),
            price: $row['price'] === null ? null : (float) $row['price'],
            condition: PuzzleCondition::from($row['condition']),
            comment: $row['comment'],
            publishedOnMarketplace: $row['published_on_marketplace'],
            reserved: $row['reserved'],
            reservedAt: $row['reserved_at'] !== null ? new DateTimeImmutable($row['reserved_at']) : null,
            reservedForName: $row['reserved_for_name'],
            puzzle: ExportablePuzzle::fromDatabaseRow($row, $uploadedAssetsBaseUrl),
        );
    }

    /**
     * Prices are stored without a currency: the list's currency at export time is the best we know.
     *
     * @return array<string, scalar|null>
     */
    public function toColumns(null|string $currency): array
    {
        return [
            'sell_swap_item_id' => $this->sellSwapItemId,
            'added_at' => $this->addedAt->format('Y-m-d H:i:s'),
            'listing_type' => $this->listingType->value,
            'price' => $this->price,
            'currency' => $this->price !== null ? $currency : null,
            'condition' => $this->condition->value,
            'comment' => $this->comment,
            'published_on_marketplace' => $this->publishedOnMarketplace,
            'reserved' => $this->reserved,
            'reserved_at' => $this->reservedAt?->format('Y-m-d H:i:s'),
            'reserved_for_name' => $this->reservedForName,
        ];
    }
}
