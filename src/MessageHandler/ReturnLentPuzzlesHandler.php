<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Exceptions\LentPuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\MultiscanBatchRejected;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ReturnLentPuzzle;
use SpeedPuzzling\Web\Message\ReturnLentPuzzles;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\MultiscanAction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ReturnLentPuzzlesHandler
{
    public function __construct(
        private MultiscanBatchGuard $guard,
        private PlayerRepository $playerRepository,
        private ReturnLentPuzzleHandler $returnLentPuzzle,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws MultiscanBatchRejected
     * @throws PlayerNotFound
     * @throws LentPuzzleNotFound
     */
    public function __invoke(ReturnLentPuzzles $message): void
    {
        $this->playerRepository->get($message->actingPlayerId);

        $report = $this->guard->check(MultiscanAction::Return, $message->actingPlayerId, $message->puzzleIds);

        foreach ($report->eligible as $puzzleId) {
            ($this->returnLentPuzzle)(new ReturnLentPuzzle(
                lentPuzzleId: $report->lentPuzzleIds[$puzzleId],
                actingPlayerId: $message->actingPlayerId,
            ));

            // A return inserts a transfer that points at the lent_puzzle row it deletes (FK SET NULL).
            // The notification event dispatched inside flushes in a savepoint, after which the
            // transfer still holds the removed entity - the next flush would report it as "new".
            // Flush each return on its own and drop the stale references before the next one.
            $this->entityManager->flush();
            $this->entityManager->clear();
        }
    }
}
