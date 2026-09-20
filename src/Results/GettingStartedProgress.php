<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The newcomer checklist (docs/features/getting-started-guide.md). Every step is read
 * from what the player has actually done - nothing is stored for the steps themselves.
 */
readonly final class GettingStartedProgress
{
    public const int TOTAL_STEPS = 5;

    public function __construct(
        public bool $hasLoggedPuzzle,
        public bool $hasFinishedProfile,
        public bool $hasFavoritePlayer,
        public bool $dismissed,
        public bool $isNewcomer,
        /**
         * The collection of what the player owns - the picker, marketplace and lending build on it
         */
        public bool $hasPuzzleInLibrary = false,
        public bool $hasSeenStatistics = false,
        public bool $hasSeenLeaderboard = false,
    ) {
    }

    /**
     * Creating the account counts as the first, already ticked step: a list that
     * starts at "1 of 5" reads as momentum, one that starts at zero reads as work.
     */
    public function doneCount(): int
    {
        return 1
            + ($this->hasLoggedPuzzle ? 1 : 0)
            + ($this->hasFinishedProfile ? 1 : 0)
            + ($this->hasPuzzleInLibrary ? 1 : 0)
            + ($this->hasFavoritePlayer ? 1 : 0);
    }

    public function isComplete(): bool
    {
        return $this->doneCount() === self::TOTAL_STEPS;
    }

    /**
     * The one step the card highlights, so the eye has a single place to go.
     *
     * @return null|'log_puzzle'|'finish_profile'|'add_to_library'|'add_favorite'
     */
    public function nextStep(): null|string
    {
        return match (true) {
            $this->hasLoggedPuzzle === false => 'log_puzzle',
            $this->hasFinishedProfile === false => 'finish_profile',
            $this->hasPuzzleInLibrary === false => 'add_to_library',
            $this->hasFavoritePlayer === false => 'add_favorite',
            default => null,
        };
    }

    public function shouldBeShown(): bool
    {
        return $this->isNewcomer && $this->dismissed === false;
    }
}
