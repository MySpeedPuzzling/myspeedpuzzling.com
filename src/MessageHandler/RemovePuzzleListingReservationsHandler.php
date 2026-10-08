<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RemoveListingReservation;
use SpeedPuzzling\Web\Message\RemovePuzzleListingReservations;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Takes the reservation off every selected listing through the single handler (its conversations are told).
 */
#[AsMessageHandler]
readonly final class RemovePuzzleListingReservationsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private SellSwapListItemRepository $sellSwapListItemRepository,
        private RemoveListingReservationHandler $removeListingReservation,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(RemovePuzzleListingReservations $message): SelectedPuzzlesOutcome
    {
        $player = $this->playerRepository->get($message->playerId);
        $puzzleIds = array_values(array_unique($message->puzzleIds));
        $items = $this->sellSwapListItemRepository->findByPlayerAndPuzzles($player, $puzzleIds);
        $changed = 0;

        foreach ($items as $item) {
            if ($item->reserved === false) {
                continue;
            }

            ($this->removeListingReservation)(new RemoveListingReservation(
                sellSwapListItemId: $item->id->toString(),
                playerId: $message->playerId,
            ));
            $changed++;
        }

        return new SelectedPuzzlesOutcome(
            changed: $changed,
            alreadyThere: count($items) - $changed,
            skipped: count($puzzleIds) - count($items),
        );
    }
}
