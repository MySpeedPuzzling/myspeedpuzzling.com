<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The safety switch of the spreadsheet import, flipped by an admin on /admin/imports
 * (docs/features/data-import/adding.md "The switch").
 */
readonly final class ChangeImportAddingPaused
{
    public function __construct(
        public bool $paused,
        public string $adminPlayerId,
        public null|string $reason,
    ) {
    }
}
