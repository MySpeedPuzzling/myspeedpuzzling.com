<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Export;

use DateTimeImmutable;

readonly final class ExportableWishListItem
{
    public const array COLUMNS = ['wish_list_item_id', 'added_at', 'remove_on_collection_add'];

    public function __construct(
        public string $wishListItemId,
        public DateTimeImmutable $addedAt,
        public bool $removeOnCollectionAdd,
        public null|ExportablePuzzle $puzzle,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row, string $uploadedAssetsBaseUrl): self
    {
        /** @var array{wish_list_item_id: string, added_at: string, remove_on_collection_add: bool} $row */
        return new self(
            wishListItemId: $row['wish_list_item_id'],
            addedAt: new DateTimeImmutable($row['added_at']),
            removeOnCollectionAdd: $row['remove_on_collection_add'],
            puzzle: ExportablePuzzle::fromDatabaseRow($row, $uploadedAssetsBaseUrl),
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toColumns(): array
    {
        return [
            'wish_list_item_id' => $this->wishListItemId,
            'added_at' => $this->addedAt->format('Y-m-d H:i:s'),
            'remove_on_collection_add' => $this->removeOnCollectionAdd,
        ];
    }
}
