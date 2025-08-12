<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class CollectionFolderInfo
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $isSystem,
        public null|string $color,
        public null|string $description,
        public int $puzzleCount,
    ) {
    }
}