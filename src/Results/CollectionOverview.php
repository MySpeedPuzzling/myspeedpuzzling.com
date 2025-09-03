<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class CollectionOverview
{
    public function __construct(
        public string $id,
        public null|string $name,
        public null|string $description,
        public bool $isPublic,
        public null|string $systemType,
        public DateTimeImmutable $createdAt,
        public null|DateTimeImmutable $updatedAt,
        public int $puzzlesCount,
    ) {
    }

    /**
     * @param array{
     *     id: string,
     *     name: null|string,
     *     description: null|string,
     *     is_public: bool,
     *     system_type: null|string,
     *     created_at: string,
     *     updated_at: null|string,
     *     puzzles_count: int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            id: $row['id'],
            name: $row['name'],
            description: $row['description'],
            isPublic: $row['is_public'],
            systemType: $row['system_type'],
            createdAt: new DateTimeImmutable($row['created_at']),
            updatedAt: $row['updated_at'] !== null ? new DateTimeImmutable($row['updated_at']) : null,
            puzzlesCount: $row['puzzles_count'],
        );
    }

    public function getDisplayName(): string
    {
        if ($this->systemType !== null) {
            return match ($this->systemType) {
                'completed' => 'Completed',
                'wishlist' => 'Wishlist',
                'todolist' => 'Todo List',
                'borrowed_to' => 'Borrowed to Someone',
                'borrowed_from' => 'Borrowed from Someone',
                'for_sale' => 'For Sale/Exchange',
                'my_collection' => 'My Collection',
                default => $this->name ?? 'Unknown',
            };
        }

        if ($this->name === null) {
            return 'My Collection';
        }

        return $this->name;
    }

    public function isSystemCollection(): bool
    {
        return $this->systemType !== null;
    }

    public function isCustomCollection(): bool
    {
        return $this->systemType === null && $this->name !== null;
    }

    public function isRootCollection(): bool
    {
        return $this->systemType === null && $this->name === null;
    }

    public function canBeDeleted(): bool
    {
        return $this->isCustomCollection();
    }

    public function canBeRenamed(): bool
    {
        return $this->isCustomCollection();
    }

    public function canAddPuzzles(): bool
    {
        // Cannot add to completed or borrowed collections directly
        return !in_array($this->systemType, ['completed', 'borrowed_to', 'borrowed_from'], true);
    }
}
