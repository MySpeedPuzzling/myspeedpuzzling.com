<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\BoxTextField;

/**
 * "Dismiss" on the box-text mismatch list (docs/features/puzzle-approvals.md, "Box-text mismatches") - remembered per
 * puzzle, field and value; dismissing the same again changes nothing.
 */
readonly final class DismissBoxTextMismatch
{
    public function __construct(
        public string $puzzleId,
        public BoxTextField $field,
        public string $valueKey,
        public string $decidedByPlayerId,
        // Admins may decide on a puzzle whose picture is hidden now - moderators never see one
        public bool $mayDecideHiddenPuzzles,
    ) {
    }
}
