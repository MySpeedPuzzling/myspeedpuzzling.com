<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\KarmaKind;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;

/**
 * One line of "What did I get my karma for?" (docs/features/karma.md) - worth 1 unless it is a puzzle that turned out
 * to be a copy of one added earlier.
 */
readonly final class KarmaItem
{
    public function __construct(
        public KarmaKind $kind,
        public null|DateTimeImmutable $at,
        /** The puzzle it is about now (a merge moves it) - null when the puzzle is gone */
        public null|string $puzzleId,
        public null|string $puzzleName,
        /** The decision a moderator made (KarmaKind::Decision only) */
        public null|PuzzleModerationAction $action,
    ) {
    }

    public function counts(): bool
    {
        return $this->kind->counts();
    }
}
