<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

readonly final class GroupSolvingTimeEdited
{
    /**
     * @param list<string> $memberPlayerIdsBeforeEdit a member the edit removed still has to hear about it
     */
    public function __construct(
        public UuidInterface $puzzleSolvingTimeId,
        public UuidInterface $editedByPlayerId,
        public array $memberPlayerIdsBeforeEdit,
    ) {
    }
}
