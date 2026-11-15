<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The Puzzles summary of an import (docs/features/data-import/pairing-screens.md "The summary"): every group with
 * how many puzzles the rules put there and how many of them still wait for the player.
 */
readonly final class ImportPuzzlesOverview
{
    /**
     * @param array<string, array{total: int, open: int}> $groups by GetImportPuzzleScreen::GROUPS
     */
    public function __construct(
        public bool $beingSetUp,
        public int $puzzles,
        public array $groups,
        /** The similar-name search has not come to them yet */
        public int $searching,
        public bool $paired,
        public int $unlisted,
        public int $leftOut,
        /** Rows whose result is on the player's profile already, or was added by an earlier import */
        public int $rowsAlreadyYours,
        public int $rowsNeedLook,
        /** Decided to be a puzzle of the database - by the rules or by the player */
        public int $matched = 0,
    ) {
    }

    /**
     * The first group that needs the player - null when nothing waits.
     */
    public function nextGroup(): null|string
    {
        foreach (['probably', 'choose', 'not-found', 'found'] as $group) {
            if (($this->groups[$group]['open'] ?? 0) > 0) {
                return $group;
            }
        }

        return null;
    }

    public function open(): int
    {
        return array_sum(array_map(static fn (array $group): int => $group['open'], $this->groups));
    }
}
