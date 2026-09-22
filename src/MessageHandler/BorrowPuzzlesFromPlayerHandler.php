<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CannotLendToSelf;
use SpeedPuzzling\Web\Exceptions\MultiscanBatchRejected;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\BorrowPuzzleFromPlayer;
use SpeedPuzzling\Web\Message\BorrowPuzzlesFromPlayer;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\MultiscanAction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class BorrowPuzzlesFromPlayerHandler
{
    public function __construct(
        private MultiscanBatchGuard $guard,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private BorrowPuzzleFromPlayerHandler $borrowPuzzleFromPlayer,
    ) {
    }

    /**
     * @throws MultiscanBatchRejected
     * @throws CannotLendToSelf
     * @throws PlayerNotFound
     * @throws PuzzleNotFound
     */
    public function __invoke(BorrowPuzzlesFromPlayer $message): void
    {
        if ($message->ownerPlayerId === null && ($message->ownerName === null || trim($message->ownerName) === '')) {
            throw new MultiscanBatchRejected(null, 'missing_person');
        }

        if ($message->ownerPlayerId === $message->borrowerPlayerId) {
            throw new CannotLendToSelf();
        }

        $this->playerRepository->get($message->borrowerPlayerId);

        if ($message->ownerPlayerId !== null) {
            $this->playerRepository->get($message->ownerPlayerId);
        }

        foreach ($message->puzzleIds as $puzzleId) {
            $this->puzzleRepository->get($puzzleId);
        }

        $report = $this->guard->check(MultiscanAction::Borrow, $message->borrowerPlayerId, $message->puzzleIds);

        foreach ($report->eligible as $puzzleId) {
            ($this->borrowPuzzleFromPlayer)(new BorrowPuzzleFromPlayer(
                borrowerPlayerId: $message->borrowerPlayerId,
                puzzleId: $puzzleId,
                ownerPlayerId: $message->ownerPlayerId,
                ownerName: $message->ownerName,
                notes: $message->notes,
            ));
        }
    }
}
