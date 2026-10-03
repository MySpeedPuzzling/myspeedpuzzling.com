<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\SellSwapListItemEvent;
use SpeedPuzzling\Web\Exceptions\CompetitionNotEligibleForMarketplace;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\MarketplaceBanned;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotGoingToCompetition;
use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\Message\BringListingToEvent;
use SpeedPuzzling\Web\Query\GetConversationPartnersForListing;
use SpeedPuzzling\Web\Query\GetMarketplaceEvents;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemEventRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Services\ListingReservation;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Bring to event" from a conversation (docs/features/marketplace/11-events.md): the listing is brought to the event -
 * idempotent, an existing link stays as it is - and with `reserveForPlayerId` also reserved for that buyer, through the
 * same ListingReservation as MarkListingAsReservedHandler (system message in every conversation about the listing).
 * The buyer must be a conversation partner of the seller about this listing (PlayerNotFound otherwise - nothing is
 * saved). A listing that is reserved already - for that buyer or anybody else - keeps its reservation untouched, only
 * the link is made. A listing that is not published gets no link (only published listings are brought), the
 * reservation still happens.
 */
#[AsMessageHandler]
readonly final class BringListingToEventHandler
{
    public function __construct(
        private SellSwapListItemRepository $sellSwapListItemRepository,
        private SellSwapListItemEventRepository $sellSwapListItemEventRepository,
        private CompetitionRepository $competitionRepository,
        private GetConversationPartnersForListing $getConversationPartnersForListing,
        private GetMarketplaceEvents $getMarketplaceEvents,
        private ListingReservation $listingReservation,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws SellSwapListItemNotFound
     * @throws MarketplaceBanned
     * @throws CompetitionNotEligibleForMarketplace
     * @throws PlayerNotGoingToCompetition
     * @throws CompetitionNotFound
     * @throws PlayerNotFound
     */
    public function __invoke(BringListingToEvent $message): void
    {
        $item = $this->sellSwapListItemRepository->get($message->listItemId);

        if ($item->player->id->toString() !== $message->playerId) {
            throw new SellSwapListItemNotFound();
        }

        if ($item->player->marketplaceBanned) {
            throw new MarketplaceBanned();
        }

        if ($this->getMarketplaceEvents->qualifies($message->competitionId) === false) {
            throw new CompetitionNotEligibleForMarketplace();
        }

        if ($this->getMarketplaceEvents->isPlayerGoing($message->competitionId, $message->playerId) === false) {
            throw new PlayerNotGoingToCompetition();
        }

        // Validated before anything is written
        $buyerId = $message->reserveForPlayerId !== null
            ? $this->conversationPartner($item->id->toString(), $item->player->id->toString(), $message->reserveForPlayerId)
            : null;

        $competition = $this->competitionRepository->get($message->competitionId);

        if ($item->publishedOnMarketplace && $this->sellSwapListItemEventRepository->find($item->id, $competition->id) === null) {
            $this->sellSwapListItemEventRepository->save(new SellSwapListItemEvent(
                sellSwapListItem: $item,
                competition: $competition,
                addedAt: $this->clock->now(),
            ));
        }

        if ($buyerId === null) {
            return;
        }

        // Reserved already - for this buyer (a second click) or for someone else (in another tab after the menu was
        // rendered): the link is made, the reservation is left as it is
        if ($item->reserved) {
            return;
        }

        $this->listingReservation->reserve($item, $buyerId);
    }

    /**
     * Only somebody the seller talks to about this very listing can get it reserved from the chat.
     *
     * @throws PlayerNotFound
     */
    private function conversationPartner(string $listItemId, string $sellerId, string $playerId): UuidInterface
    {
        foreach ($this->getConversationPartnersForListing->forListingAndSeller($listItemId, $sellerId) as $partner) {
            if (strtolower($partner->playerId) === strtolower($playerId)) {
                return Uuid::fromString($partner->playerId);
            }
        }

        throw new PlayerNotFound();
    }
}
