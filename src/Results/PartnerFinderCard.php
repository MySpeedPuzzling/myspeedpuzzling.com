<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PartnerCardState;
use SpeedPuzzling\Web\Value\PartnerKind;

/**
 * The partner finder on an event or edition page (docs/features/partner-finder.md "Part B", built by
 * PartnerFinderCardResolver): the "Find a partner (N)" button - always there while the event has a finder - and the card
 * in one of its states. Templates: event_parts/_partner_finder_button.html.twig, event_parts/_partner_card.html.twig.
 */
readonly final class PartnerFinderCard
{
    public function __construct(
        // The finder page; null only while its route does not exist (see PartnerFinderCardResolver)
        public null|string $finderUrl,
        // "List me" (partner_listing_form, opened in modal-frame); null only while its route does not exist
        public null|string $listingFormUrl,
        // What the event's rounds offer - the card's wording (pair, team or both)
        public PartnerFinderOffer $offer,
        // Signed-in viewers only: GetPartnerCardData - null for a guest (a plain link, no count, no query)
        public null|PartnerCardData $data = null,
        // Null = no card (a guest, in a pair/team entry, closed, not going and nobody listed)
        public null|PartnerCardState $state = null,
        // Right after "I'm going" (EventJustJoined) - the invitation, highlighted
        public bool $highlighted = false,
    ) {
    }

    /**
     * The button's count: null for a guest (no query), else the open listings of the event
     */
    public function count(): null|int
    {
        return $this->data?->listingCount;
    }

    /**
     * 'pair', 'team' or 'both' - what the event offers, for the card's wording
     */
    public function offeredKey(): string
    {
        return self::kindsKey($this->offer->kinds);
    }

    /**
     * 'pair', 'team' or 'both' - what the viewer's own listing looks for
     */
    public function ownKey(): string
    {
        return self::kindsKey($this->data->ownLookingFor ?? []);
    }

    /**
     * @param list<PartnerKind> $kinds
     */
    private static function kindsKey(array $kinds): string
    {
        $pair = in_array(PartnerKind::Pair, $kinds, true);
        $team = in_array(PartnerKind::Team, $kinds, true);

        return match (true) {
            $pair && $team === false => 'pair',
            $team && $pair === false => 'team',
            default => 'both',
        };
    }
}
