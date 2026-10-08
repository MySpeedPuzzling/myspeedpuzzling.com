<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ReservePuzzleListings;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Services\ListingReservation;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Reserves every selected listing for nobody named, telling its conversations like a single reservation does.
 * Listings reserved already keep their reservation (and whom it is for).
 */
#[AsMessageHandler]
readonly final class ReservePuzzleListingsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private SellSwapListItemRepository $sellSwapListItemRepository,
        private ListingReservation $listingReservation,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(ReservePuzzleListings $message): SelectedPuzzlesOutcome
    {
        $player = $this->playerRepository->get($message->playerId);
        $puzzleIds = array_values(array_unique($message->puzzleIds));
        $items = $this->sellSwapListItemRepository->findByPlayerAndPuzzles($player, $puzzleIds);
        $changed = 0;

        foreach ($items as $item) {
            if ($item->reserved) {
                continue;
            }

            $this->listingReservation->reserve($item, null);
            $changed++;
        }

        return new SelectedPuzzlesOutcome(
            changed: $changed,
            alreadyThere: count($items) - $changed,
            skipped: count($puzzleIds) - count($items),
        );
    }
}
