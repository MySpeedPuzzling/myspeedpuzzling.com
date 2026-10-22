<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PartnerKind;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * What the partner finder offers on an event (docs/features/partner-finder.md D1): the kinds of its rounds (duo → pair,
 * team → team), both when it has no rounds at all, none when every round is solo. $teamSize = the largest expected
 * team size set on its team rounds, null when none is set (or it has no team round).
 */
readonly final class PartnerFinderOffer
{
    /**
     * @param list<PartnerKind> $kinds in PartnerKind order
     */
    public function __construct(
        public array $kinds,
        public null|int $teamSize = null,
    ) {
    }

    /**
     * @param list<RoundCategory> $roundCategories every round of the event, [] = no rounds
     */
    public static function fromRoundCategories(array $roundCategories, null|int $teamSize = null): self
    {
        if ($roundCategories === []) {
            return new self(PartnerKind::cases(), $teamSize);
        }

        $kinds = [];

        foreach (PartnerKind::cases() as $kind) {
            foreach ($roundCategories as $category) {
                if (PartnerKind::fromRoundCategory($category) === $kind) {
                    $kinds[] = $kind;
                    break;
                }
            }
        }

        return new self($kinds, in_array(PartnerKind::Team, $kinds, true) ? $teamSize : null);
    }

    public function offersAny(): bool
    {
        return $this->kinds !== [];
    }

    public function offers(PartnerKind $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }
}
