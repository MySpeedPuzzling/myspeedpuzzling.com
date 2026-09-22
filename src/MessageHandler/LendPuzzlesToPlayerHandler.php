<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CannotLendToSelf;
use SpeedPuzzling\Web\Exceptions\MultiscanBatchRejected;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\LendPuzzlesToPlayer;
use SpeedPuzzling\Web\Message\LendPuzzleToPlayer;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\MultiscanAction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class LendPuzzlesToPlayerHandler
{
    public function __construct(
        private MultiscanBatchGuard $guard,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private LendPuzzleToPlayerHandler $lendPuzzleToPlayer,
    ) {
    }

    /**
     * @throws MultiscanBatchRejected
     * @throws CannotLendToSelf
     * @throws PlayerNotFound
     * @throws PuzzleNotFound
     */
    public function __invoke(LendPuzzlesToPlayer $message): void
    {
        if ($message->borrowerPlayerId === null && ($message->borrowerName === null || trim($message->borrowerName) === '')) {
            throw new MultiscanBatchRejected(null, 'missing_person');
        }

        if ($message->borrowerPlayerId === $message->ownerPlayerId) {
            throw new CannotLendToSelf();
        }

        $this->playerRepository->get($message->ownerPlayerId);

        if ($message->borrowerPlayerId !== null) {
            $this->playerRepository->get($message->borrowerPlayerId);
        }

        foreach ($message->puzzleIds as $puzzleId) {
            $this->puzzleRepository->get($puzzleId);
        }

        $report = $this->guard->check(MultiscanAction::Lend, $message->ownerPlayerId, $message->puzzleIds);

        foreach ($report->eligible as $puzzleId) {
            ($this->lendPuzzleToPlayer)(new LendPuzzleToPlayer(
                ownerPlayerId: $message->ownerPlayerId,
                puzzleId: $puzzleId,
                borrowerPlayerId: $message->borrowerPlayerId,
                borrowerName: $message->borrowerName,
                notes: $message->notes,
            ));
        }
    }
}
