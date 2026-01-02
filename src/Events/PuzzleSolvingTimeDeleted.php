<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;

readonly final class PuzzleSolvingTimeDeleted
{
    public function __construct(
        public UuidInterface $puzzleId,
    ) {
    }

    public static function fromEntity(PuzzleSolvingTime $entity): self
    {
        return new self($entity->puzzle->id);
    }
}
