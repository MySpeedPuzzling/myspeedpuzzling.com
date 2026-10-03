<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\SellSwapListItemEvent;
use SpeedPuzzling\Web\Exceptions\CompetitionNotEligibleForMarketplace;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\MarketplaceBanned;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotGoingToCompetition;
use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\Message\ChooseEventOffers;
use SpeedPuzzling\Web\Query\GetMarketplaceEvents;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemEventRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The picker's whole choice for one event (docs/features/marketplace/11-events.md): exactly the chosen listings are
 * brought. Only this (player, event) pair is touched - links to other events, past ones included, stay. A chosen
 * listing that is not published is skipped (an existing link of it is kept, no new one is made), one that does not
 * exist any more is ignored, someone else's is refused (SellSwapListItemNotFound).
 */
#[AsMessageHandler]
readonly final class ChooseEventOffersHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private CompetitionRepository $competitionRepository,
        private SellSwapListItemRepository $sellSwapListItemRepository,
        private SellSwapListItemEventRepository $sellSwapListItemEventRepository,
        private GetMarketplaceEvents $getMarketplaceEvents,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws MarketplaceBanned
     * @throws CompetitionNotEligibleForMarketplace
     * @throws PlayerNotGoingToCompetition
     * @throws CompetitionNotFound
     * @throws SellSwapListItemNotFound
     */
    public function __invoke(ChooseEventOffers $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        if ($player->marketplaceBanned) {
            throw new MarketplaceBanned();
        }

        if ($this->getMarketplaceEvents->qualifies($message->competitionId) === false) {
            throw new CompetitionNotEligibleForMarketplace();
        }

        if ($this->getMarketplaceEvents->isPlayerGoing($message->competitionId, $message->playerId) === false) {
            throw new PlayerNotGoingToCompetition();
        }

        $competition = $this->competitionRepository->get($message->competitionId);

        $chosen = [];

        // A listing removed in the meantime (sold in another tab) is simply no longer chosen - someone else's is refused
        foreach ($this->sellSwapListItemRepository->findMany($message->listItemIds) as $item) {
            if ($item->player->id->equals($player->id) === false) {
                throw new SellSwapListItemNotFound();
            }

            $chosen[$item->id->toString()] = $item;
        }

        foreach ($this->sellSwapListItemEventRepository->forPlayerAndCompetition($message->playerId, $competition->id->toString()) as $existing) {
            $itemId = $existing->sellSwapListItem->id->toString();

            if (array_key_exists($itemId, $chosen)) {
                unset($chosen[$itemId]);
                continue;
            }

            $this->sellSwapListItemEventRepository->delete($existing);
        }

        $now = $this->clock->now();

        foreach ($chosen as $item) {
            if ($item->publishedOnMarketplace === false) {
                continue;
            }

            $this->sellSwapListItemEventRepository->save(new SellSwapListItemEvent(
                sellSwapListItem: $item,
                competition: $competition,
                addedAt: $now,
            ));
        }
    }
}
