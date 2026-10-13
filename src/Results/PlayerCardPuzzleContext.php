<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The player card's puzzle context (docs/features/navigation CARD-02): the player's solo standing on the puzzle the card
 * was opened on - the puzzle's own leaderboard (GetPuzzleResultDetail::soloBestCte()) - their attempts there and, for a
 * signed-in viewer, the viewer's own best for the gap. A player who solved it only in pairs or teams gets that line.
 */
readonly final class PlayerCardPuzzleContext
{
    public function __construct(
        public string $puzzleId,
        // The player's place on the puzzle's solo leaderboard, null without a timed solo result there
        public null|int $rank,
        public int $total,
        public null|int $bestTime,
        // Every result of the player on the puzzle, solo and in pairs/teams (none awaiting verification)
        public int $attempts,
        public int $groupAttempts,
        // The viewer's own best solo time on the puzzle (null: none, or a guest, or the card is the viewer's own)
        public null|int $viewerTime,
        public bool $viewerSignedIn,
        // Own card: one of the owner's results on the puzzle - the result detail to open ("Your results on this puzzle")
        public null|string $ownTimeId,
    ) {
    }

    /** The block has something to say: a solo standing, or results in pairs/teams only */
    public function hasLine(): bool
    {
        return $this->rank !== null || $this->groupAttempts > 0;
    }

    /** Positive = the viewer is slower than the player, negative = faster */
    public function viewerGap(): null|int
    {
        if ($this->viewerTime === null || $this->bestTime === null) {
            return null;
        }

        return $this->viewerTime - $this->bestTime;
    }
}
