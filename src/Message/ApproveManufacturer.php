<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\MergeDecisionSource;

/**
 * Approves a brand on its own. The approval queue approves a brand together with
 * its pending puzzle - a brand whose puzzles are all approved never shows up there
 * and would stay hidden from every other player's brand picker.
 */
readonly final class ApproveManufacturer
{
    public function __construct(
        public string $manufacturerId,
        public string $reviewerId,
        public MergeDecisionSource $decisionSource,
        // The name as the brand itself writes it - null keeps it
        public null|string $name = null,
        public null|string $decisionNote = null,
    ) {
    }
}
