<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One computed XP receipt line — output of the XpCalculator, input for the XpLedger.
 */
final readonly class XpAward
{
    public function __construct(
        public XpReason $reason,
        public int $amount,
    ) {
    }
}
