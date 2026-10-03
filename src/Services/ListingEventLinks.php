<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Entity\SellSwapListItemEvent;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Query\GetMarketplaceEvents;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemEventRepository;

/**
 * The listing form's "I'm bringing it to" (docs/features/marketplace/11-events.md), for AddPuzzleToSellSwapListHandler
 * and EditSellSwapListItemHandler: among the marketplace events the seller goes to RIGHT NOW, exactly the chosen ones
 * are linked. Links to any other event (over, left, no longer public) are history and stay untouched. A chosen id the
 * seller does not go to (any more) is ignored - the form may be older than that change. New links only for a published
 * listing of a seller who is not banned from the marketplace; unticking always removes.
 */
readonly final class ListingEventLinks
{
    public function __construct(
        private GetMarketplaceEvents $getMarketplaceEvents,
        private SellSwapListItemEventRepository $sellSwapListItemEventRepository,
        private CompetitionRepository $competitionRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $competitionIds
     * @throws CompetitionNotFound
     */
    public function syncWithListingForm(SellSwapListItem $item, array $competitionIds): void
    {
        $events = $this->getMarketplaceEvents->forPlayer($item->player->id->toString());

        if ($events === []) {
            return;
        }

        $chosen = array_flip(array_map(strtolower(...), $competitionIds));
        $canAdd = $item->publishedOnMarketplace && $item->player->marketplaceBanned === false;

        $existing = [];
        foreach ($this->sellSwapListItemEventRepository->forListItem($item->id->toString()) as $link) {
            $existing[$link->competition->id->toString()] = $link;
        }

        foreach ($events as $event) {
            $link = $existing[$event->competitionId] ?? null;
            $isChosen = array_key_exists($event->competitionId, $chosen);

            if ($isChosen === false && $link !== null) {
                $this->sellSwapListItemEventRepository->delete($link);
            }

            if ($isChosen && $link === null && $canAdd) {
                $this->sellSwapListItemEventRepository->save(new SellSwapListItemEvent(
                    sellSwapListItem: $item,
                    competition: $this->competitionRepository->get($event->competitionId),
                    addedAt: $this->clock->now(),
                ));
            }
        }
    }
}
