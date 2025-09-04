<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class UpdatePuzzleCollection
{
    public function __construct(
        public string $collectionId,
        public string $name,
        public null|string $description,
        public bool $isPublic,
    ) {
    }
}
