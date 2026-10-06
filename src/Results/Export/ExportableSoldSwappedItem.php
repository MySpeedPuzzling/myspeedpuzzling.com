<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Export;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ListingType;

/**
 * No currency: sold_swapped_item stores none, and today's list currency may not be the one the sale was in.
 */
readonly final class ExportableSoldSwappedItem
{
    public const array COLUMNS = ['sold_swapped_item_id', 'sold_at', 'listing_type', 'price', 'buyer_name', 'buyer_code', 'buyer_is_registered'];

    public function __construct(
        public string $soldSwappedItemId,
        public DateTimeImmutable $soldAt,
        public ListingType $listingType,
        public null|float $price,
        public null|string $buyerName,
        public null|string $buyerCode,
        public bool $buyerRegistered,
        public null|ExportablePuzzle $puzzle,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row, string $uploadedAssetsBaseUrl): self
    {
        /** @var array{sold_swapped_item_id: string, sold_at: string, listing_type: string, price: null|float|string, buyer_name: null|string, buyer_code: null|string, buyer_registered: bool} $row */
        return new self(
            soldSwappedItemId: $row['sold_swapped_item_id'],
            soldAt: new DateTimeImmutable($row['sold_at']),
            listingType: ListingType::from($row['listing_type']),
            price: $row['price'] === null ? null : (float) $row['price'],
            buyerName: $row['buyer_name'],
            buyerCode: $row['buyer_code'],
            buyerRegistered: $row['buyer_registered'],
            puzzle: ExportablePuzzle::fromDatabaseRow($row, $uploadedAssetsBaseUrl),
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toColumns(): array
    {
        return [
            'sold_swapped_item_id' => $this->soldSwappedItemId,
            'sold_at' => $this->soldAt->format('Y-m-d H:i:s'),
            'listing_type' => $this->listingType->value,
            'price' => $this->price,
            'buyer_name' => $this->buyerName,
            'buyer_code' => $this->buyerCode,
            'buyer_is_registered' => $this->buyerRegistered,
        ];
    }
}
