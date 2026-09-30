<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\FirstTryConflictChanged;
use SpeedPuzzling\Web\Message\ResolveFirstTryConflict;
use SpeedPuzzling\Web\Query\GetFirstTryTimes;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The player picked which of their results of a puzzle stays the first try - or none. Only results the player
 * took part in are ever touched, re-read here rather than trusted from the page.
 */
#[AsMessageHandler]
readonly final class ResolveFirstTryConflictHandler
{
    public function __construct(
        private GetFirstTryTimes $getFirstTryTimes,
        private PlayerRepository $playerRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
    ) {
    }

    /**
     * @throws FirstTryConflictChanged
     */
    public function __invoke(ResolveFirstTryConflict $message): void
    {
        $markedTimeIds = $this->getFirstTryTimes->markedTimeIdsOf($message->playerId, $message->puzzleId);

        if (count($markedTimeIds) < 2) {
            throw new FirstTryConflictChanged();
        }

        if ($message->keepTimeId !== null && in_array(strtolower($message->keepTimeId), $markedTimeIds, true) === false) {
            throw new FirstTryConflictChanged();
        }

        $player = $this->playerRepository->get($message->playerId);

        foreach ($markedTimeIds as $timeId) {
            if ($message->keepTimeId !== null && $timeId === strtolower($message->keepTimeId)) {
                continue;
            }

            $this->puzzleSolvingTimeRepository->get($timeId)->unmarkFirstAttempt($player);
        }
    }
}
