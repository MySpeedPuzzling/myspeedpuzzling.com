<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Export;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CollectionVisibility;

readonly final class ExportableCollection
{
    public function __construct(
        public string $collectionId,
        public string $name,
        public null|string $description,
        public CollectionVisibility $visibility,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array{collection_id: string, name: string, description: null|string, visibility: string, created_at: string} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            collectionId: $row['collection_id'],
            name: $row['name'],
            description: $row['description'],
            visibility: CollectionVisibility::from($row['visibility']),
            createdAt: new DateTimeImmutable($row['created_at']),
        );
    }
}
