<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The tiers of a link suggestion (docs/features/competitions-management/participant-linking.md §8.4, R10 - the fourth,
 * "Name only", is cut), best first. The number is the query's (GetParticipantLinkSuggestions orders by it).
 */
enum ParticipantLinkSuggestionTier: string
{
    /** A self-joined row of the same event with the same name - accepting merges the two */
    case JoinedByThemselves = 'joined_themselves';

    /** A linked participant with the same name at another event of the same series or organization */
    case LinkedBefore = 'linked_before';

    /** A player with the same name and country, while the name is not common */
    case NameAndCountry = 'name_country';

    public static function fromNumber(int $tier): self
    {
        return match ($tier) {
            1 => self::JoinedByThemselves,
            2 => self::LinkedBefore,
            default => self::NameAndCountry,
        };
    }
}
