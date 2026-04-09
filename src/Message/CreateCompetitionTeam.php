<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class CreateCompetitionTeam
{
    public function __construct(
        public UuidInterface $teamId,
        public string $roundId,
        public null|string $name,
    ) {
    }
}
