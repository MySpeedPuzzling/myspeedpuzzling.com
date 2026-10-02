<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The players who have somebody in their favorites, as that somebody may see them: the followers they are allowed to
 * see by name, and how many more followers have a private profile hidden from them - counted, never identified.
 */
readonly final class PlayerFollowers
{
    /**
     * @param list<PlayerIdentification> $players
     */
    public function __construct(
        public array $players,
        public int $privateCount,
    ) {
    }

    public function total(): int
    {
        return count($this->players) + $this->privateCount;
    }
}
