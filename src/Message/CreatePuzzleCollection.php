<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class CreatePuzzleCollection
{
    public function __construct(
        public UuidInterface $collectionId,
        public string $playerId,
        public string $name,
        public null|string $description,
        public bool $isPublic,
        public null|string $systemType = null,
    ) {
    }
}
