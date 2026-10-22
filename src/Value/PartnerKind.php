<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a partner finder listing looks for, and what a partner request is about (docs/features/partner-finder.md):
 * a pair partner or a team. An event offers the kinds of its rounds (duo → pair, team → team); a solo round offers none.
 */
enum PartnerKind: string
{
    case Pair = 'pair';
    case Team = 'team';

    public static function fromRoundCategory(RoundCategory $category): null|self
    {
        return match ($category) {
            RoundCategory::Solo => null,
            RoundCategory::Duo => self::Pair,
            RoundCategory::Team => self::Team,
        };
    }

    public function translationKey(): string
    {
        return 'partner_finder.kind.' . $this->value;
    }
}
