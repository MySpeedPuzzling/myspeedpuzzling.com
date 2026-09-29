<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\Uuid;

/**
 * "Only puzzles from my ..." filter of the puzzle database, as it travels in
 * the URL: the list kind, or `collection:<uuid>` for a custom collection.
 * Always about the viewer's own lists - the query scopes every kind to the
 * viewer, so a collection id of somebody else simply matches nothing.
 */
final readonly class PuzzleSearchList
{
    private const string COLLECTION_PREFIX = 'collection:';

    private function __construct(
        public PuzzleSearchListKind $kind,
        public null|string $collectionId,
    ) {
    }

    public static function tryFrom(null|string $value): null|self
    {
        if ($value === null) {
            return null;
        }

        if (str_starts_with($value, self::COLLECTION_PREFIX)) {
            $collectionId = substr($value, strlen(self::COLLECTION_PREFIX));

            return Uuid::isValid($collectionId) ? self::collection($collectionId) : null;
        }

        $kind = PuzzleSearchListKind::tryFrom($value);

        if ($kind === null || $kind === PuzzleSearchListKind::Collection) {
            return null;
        }

        return new self($kind, null);
    }

    public static function collection(string $collectionId): self
    {
        return new self(PuzzleSearchListKind::Collection, strtolower($collectionId));
    }

    public function value(): string
    {
        if ($this->kind === PuzzleSearchListKind::Collection) {
            return self::COLLECTION_PREFIX . $this->collectionId;
        }

        return $this->kind->value;
    }

    public function isMembersOnly(): bool
    {
        return $this->kind->isMembersOnly();
    }
}
