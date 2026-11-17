<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The player places their time in a round of its event - "Choose the round" on the result detail, "This is my time -
 * link it to <round>" on the round page (docs/features/competitions-management/shared-puzzles-and-divisions.md §2.4
 * row 16, A4 + A9). The round must be a candidate of the time (a round of its event and solo/pair/team holding its
 * puzzle); it is kept as the player's choice (round_chosen) while it stays one. A round id only - there is no "none of
 * these" (A4). Allowed for the time's tracker and every registered member of its pair/team.
 */
readonly final class ChooseSolvingTimeRound
{
    public function __construct(
        public string $timeId,
        public string $roundId,
        public string $playerId,
    ) {
    }
}
