<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ProposedChangeResult;

/**
 * One change a player proposed and what the review did with it (PuzzleChangeRequestOutcome).
 */
readonly final class ProposedChangeOutcome
{
    public function __construct(
        public string $label,
        // As it was when proposed - null = not set
        public null|string $before,
        // '' = every code removed
        public null|string $proposed,
        // Null = not recorded (approvals before the decision log held the puzzle before and after)
        public null|ProposedChangeResult $result,
        // What the reviewer saved instead - an Altered change only
        public null|string $saved = null,
        // Before, proposed and saved are image paths
        public bool $image = false,
        // A line of the names: main_title, main_title_language, added, changed, removed - null for other fields
        public null|string $nameKind = null,
    ) {
    }
}
