<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PlayerCardBoard;

/**
 * The player card's leaderboard context (docs/features/navigation CARD-02): the player's place on the board the card was
 * opened from - and the viewer's, when they are on it too - read from the board's own SQL (positionsOf() of
 * GetFastestPlayers, GetPlayerRatingRanking, GetXpLeaderboard), so the places are the board's.
 */
readonly final class PlayerCardBoardContext
{
    public function __construct(
        public PlayerCardBoard $board,
        public int $rank,
        public int $total,
        // Seconds (Times), the MSP Rating × 1000, XP, Achievement Points or karma
        public int $value,
        public null|int $viewerRank,
    ) {
    }
}
