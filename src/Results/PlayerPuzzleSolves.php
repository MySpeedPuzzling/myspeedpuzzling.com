<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One player's own history on one puzzle, always split by discipline - solo,
 * duo and team are different disciplines on MySpeedPuzzling and are never
 * merged (the same split as /me/statistics and /me/results?type=).
 */
readonly final class PlayerPuzzleSolves
{
    public function __construct(
        public string $puzzleId,
        public PlayerPuzzleSolvesGroup $solo,
        public PlayerPuzzleSolvesGroup $duo,
        public PlayerPuzzleSolvesGroup $team,
    ) {
    }

    public static function empty(string $puzzleId): self
    {
        return new self(
            puzzleId: $puzzleId,
            solo: PlayerPuzzleSolvesGroup::empty(),
            duo: PlayerPuzzleSolvesGroup::empty(),
            team: PlayerPuzzleSolvesGroup::empty(),
        );
    }
}
