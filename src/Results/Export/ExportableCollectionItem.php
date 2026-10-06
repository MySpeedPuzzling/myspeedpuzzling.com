<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Export;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\Collection;

readonly final class ExportableCollectionItem
{
    public const array COLUMNS = ['collection_item_id', 'collection_id', 'collection_name', 'added_at', 'comment'];

    public function __construct(
        public string $collectionItemId,
        public null|string $collectionId,
        public null|string $collectionName,
        public DateTimeImmutable $addedAt,
        public null|string $comment,
        public null|ExportablePuzzle $puzzle,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row, string $uploadedAssetsBaseUrl): self
    {
        /** @var array{collection_item_id: string, collection_id: null|string, collection_name: null|string, added_at: string, comment: null|string} $row */
        return new self(
            collectionItemId: $row['collection_item_id'],
            collectionId: $row['collection_id'],
            collectionName: $row['collection_name'],
            addedAt: new DateTimeImmutable($row['added_at']),
            comment: $row['comment'],
            puzzle: ExportablePuzzle::fromDatabaseRow($row, $uploadedAssetsBaseUrl),
        );
    }

    /**
     * The system collection has no row of its own: its id is Collection::SYSTEM_ID so items join to it.
     *
     * @return array<string, scalar|null>
     */
    public function toColumns(string $systemCollectionName): array
    {
        return [
            'collection_item_id' => $this->collectionItemId,
            'collection_id' => $this->collectionId ?? Collection::SYSTEM_ID,
            'collection_name' => $this->collectionId === null ? $systemCollectionName : $this->collectionName,
            'added_at' => $this->addedAt->format('Y-m-d H:i:s'),
            'comment' => $this->comment,
        ];
    }
}
