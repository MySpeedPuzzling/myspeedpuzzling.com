<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

/**
 * One achievement of the XP launch reveal: its highest earned tier (null = a single-tier achievement) and the XP all of
 * its earned tiers brought - the ledger grants every tier its points once (Gold = Bronze 5 + Silver 10 + Gold 25).
 */
readonly final class XpLaunchRevealBadge
{
    public function __construct(
        public BadgeType $type,
        public null|BadgeTier $tier,
        public int $xp,
        public string $badgeId,
        public bool $revealed,
    ) {
    }

    /**
     * What the highest tier asked for ("Solve 500 distinct puzzles"); a single-tier achievement has no ladder, its
     * description says why it was given.
     */
    public function requirementTranslationKey(): string
    {
        if ($this->tier === null) {
            return 'badges.description.' . $this->type->value;
        }

        return 'badges.requirement.' . $this->type->value . '_' . $this->tier->value;
    }
}
