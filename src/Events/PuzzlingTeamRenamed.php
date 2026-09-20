<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

readonly final class PuzzlingTeamRenamed
{
    public function __construct(
        public UuidInterface $teamId,
        public UuidInterface $renamedByPlayerId,
    ) {
    }
}
