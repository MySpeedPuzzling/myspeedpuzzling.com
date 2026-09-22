<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\MultiscanBatchRejected;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\AddPuzzlesToWishList;
use SpeedPuzzling\Web\Message\AddPuzzleToWishList;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\MultiscanAction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPuzzlesToWishListHandler
{
    public function __construct(
        private MultiscanBatchGuard $guard,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private AddPuzzleToWishListHandler $addPuzzleToWishList,
    ) {
    }

    /**
     * @throws MultiscanBatchRejected
     * @throws PlayerNotFound
     * @throws PuzzleNotFound
     */
    public function __invoke(AddPuzzlesToWishList $message): void
    {
        $this->playerRepository->get($message->playerId);

        foreach ($message->puzzleIds as $puzzleId) {
            $this->puzzleRepository->get($puzzleId);
        }

        $report = $this->guard->check(MultiscanAction::AddToWishlist, $message->playerId, $message->puzzleIds);

        foreach ($report->eligible as $puzzleId) {
            ($this->addPuzzleToWishList)(new AddPuzzleToWishList(
                playerId: $message->playerId,
                puzzleId: $puzzleId,
            ));
        }
    }
}
