<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What the marketplace card of an event page says (GetEventOffers::summary(), docs/features/marketplace/11-events.md):
 * the offers of the sellers going to the event, split into what they are bringing (a sell_swap_list_item_event row)
 * and what buyers can ask them to bring (no row), plus the viewer's own line.
 *
 * The counts are aggregates over everybody - deliberately unfiltered by the viewer's blocks; only the faces in
 * `sellers` leave out the players the viewer has hidden.
 */
readonly final class EventOffersSummary
{
    public function __construct(
        /** Published offers of going sellers marked for this event */
        public int $bringingCount,
        /** Published offers of going sellers NOT marked for this event - "ask to bring it" */
        public int $askCount,
        /** Going players with at least one published offer */
        public int $sellersCount,
        /** @var list<EventOffersSeller> up to GetEventOffers::SELLER_FACES, bringing sellers first, then the most offers */
        public array $sellers,
        /** The viewer's published offers marked for this event (0 for a guest) */
        public int $viewerBringingCount,
        /** All the viewer's published offers, whichever events (0 for a guest) */
        public int $viewerPublishedCount,
    ) {
    }

    public function totalCount(): int
    {
        return $this->bringingCount + $this->askCount;
    }
}
