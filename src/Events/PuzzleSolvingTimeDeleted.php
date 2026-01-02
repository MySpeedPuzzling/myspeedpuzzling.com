<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;

readonly final class PuzzleSolvingTimeDeleted implements DeleteDomainEvent
{
    public function __construct(
        public UuidInterface $puzzleId,
    ) {
    }

    public static function fromEntity(object $entity): static
    {
        if (!$entity instanceof PuzzleSolvingTime) {
            throw new \InvalidArgumentException('Expected PuzzleSolvingTime entity');
        }

        return new self($entity->puzzle->id);
    }
}
