<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\LentPuzzle;
use SpeedPuzzling\Web\Entity\LentPuzzleTransfer;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\LendPuzzleToPlayer;
use SpeedPuzzling\Web\Repository\LentPuzzleRepository;
use SpeedPuzzling\Web\Repository\LentPuzzleTransferRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\TransferType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class LendPuzzleToPlayerHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private LentPuzzleRepository $lentPuzzleRepository,
        private LentPuzzleTransferRepository $lentPuzzleTransferRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws PuzzleNotFound
     */
    public function __invoke(LendPuzzleToPlayer $message): void
    {
        $owner = $this->playerRepository->get($message->ownerPlayerId);
        $borrower = $this->playerRepository->get($message->borrowerPlayerId);
        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        // Check if puzzle is already lent by this owner
        $existingLent = $this->lentPuzzleRepository->findByOwnerAndPuzzle($owner, $puzzle);

        if ($existingLent !== null) {
            // Already lent, update the holder
            $existingLent->changeCurrentHolder($borrower);
            $existingLent->changeNotes($message->notes);

            // Record the transfer (pass)
            $transfer = new LentPuzzleTransfer(
                Uuid::uuid7(),
                $existingLent,
                $existingLent->currentHolderPlayer,
                $borrower,
                new DateTimeImmutable(),
                TransferType::Pass,
            );

            $this->lentPuzzleTransferRepository->save($transfer);

            return;
        }

        $now = new DateTimeImmutable();

        $lentPuzzle = new LentPuzzle(
            Uuid::uuid7(),
            $puzzle,
            $owner,
            $borrower,
            $now,
            $message->notes,
        );

        $this->lentPuzzleRepository->save($lentPuzzle);

        // Record the initial transfer
        $transfer = new LentPuzzleTransfer(
            Uuid::uuid7(),
            $lentPuzzle,
            null, // from owner (null indicates initial lend)
            $borrower,
            $now,
            TransferType::InitialLend,
        );

        $this->lentPuzzleTransferRepository->save($transfer);
    }
}
