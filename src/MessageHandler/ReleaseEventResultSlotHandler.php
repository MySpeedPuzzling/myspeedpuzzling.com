<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\EventResultClaimRefused;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Message\ReleaseEventResultSlot;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Services\EventResultsImport\EventResultSlotRelease;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultClaimAction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Remove from my profile" on an imported event result - what "Delete" is for the player's other results
 * (docs/features/event-results-import/README.md "Gates"). The result stays, the player's spot is anonymous again.
 */
#[AsMessageHandler]
readonly final class ReleaseEventResultSlotHandler
{
    public function __construct(
        private PuzzleSolvingTimeRepository $solvingTimeRepository,
        private PlayerRepository $playerRepository,
        private EventResultSlotRelease $eventResultSlotRelease,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     * @throws PlayerNotFound
     * @throws EventResultClaimRefused
     */
    public function __invoke(ReleaseEventResultSlot $message): void
    {
        $time = $this->solvingTimeRepository->get($message->timeId);
        $player = $this->playerRepository->get($message->playerId);

        $this->eventResultSlotRelease->release($time, $player, ResultClaimAction::Released, by: $player);
    }
}
