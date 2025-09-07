<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class CollectionItemOverview
{
    public function __construct(
        public string $collectionItemId,
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        public null|string $manufacturerName,
        public null|string $image,
        public null|string $comment,
        public DateTimeImmutable $addedAt,
    ) {
    }
}
