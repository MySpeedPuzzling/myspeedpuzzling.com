<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The one line above an achievement's name in the XP launch deck that says what is special about it - at most one per
 * card, and only when it is true (GetXpLaunchRevealBadges::withFacts()). Every card has its own facts (tier, what it
 * took, how many hold it, the next tier); the highlight makes the cards that stand out read like it (Jan 2026-10-11:
 * "the same texts, just a different screen").
 */
enum XpLaunchRevealHighlight: string
{
    // Early Adopter: given to the players who believed in the site early
    case Thanks = 'thanks';

    // Diamond: nothing above it
    case TopTier = 'top_tier';

    // The player's rarest tier in the deck (fewest holders), when it is not a common one
    case Rarest = 'rarest';

    // The next tier is three quarters done or more
    case WithinReach = 'within_reach';

    public function translationKey(): string
    {
        return 'xp.reveal.card.highlight.' . $this->value;
    }

    /** Bootstrap icon of the pill */
    public function icon(): string
    {
        return match ($this) {
            self::Thanks => 'bi-heart-fill',
            self::TopTier => 'bi-gem',
            self::Rarest => 'bi-stars',
            self::WithinReach => 'bi-flag-fill',
        };
    }
}
