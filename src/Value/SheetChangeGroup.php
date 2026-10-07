<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Changes the server applies all together or not at all - one organiser action (typing a pair into the new row, a
 * paste, a move), with the page's own id to answer it by.
 */
readonly final class SheetChangeGroup
{
    /**
     * @param non-empty-list<SheetChange> $changes
     */
    public function __construct(
        public string $id,
        public array $changes,
    ) {
    }
}
