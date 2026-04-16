<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Repository\PlayerRepository;

readonly final class FakePlayerRepository extends PlayerRepository
{
    public function __construct(private Player $player)
    {
        // Skip parent constructor — only `get()` is exercised in tests.
    }

    public function get(string $playerId): Player
    {
        return $this->player;
    }
}
