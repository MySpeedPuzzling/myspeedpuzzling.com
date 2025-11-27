<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\LentPuzzle;
use SpeedPuzzling\Web\Entity\LentPuzzleTransfer;
use SpeedPuzzling\Web\Events\PuzzleBorrowed;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\LendPuzzleToPlayer;
use SpeedPuzzling\Web\Repository\LentPuzzleRepository;
use SpeedPuzzling\Web\Repository\LentPuzzleTransferRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\TransferType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class LendPuzzleToPlayerHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private LentPuzzleRepository $lentPuzzleRepository,
        private LentPuzzleTransferRepository $lentPuzzleTransferRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws PuzzleNotFound
     */
    public function __invoke(LendPuzzleToPlayer $message): void
    {
        $owner = $this->playerRepository->get($message->ownerPlayerId);
        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        // Resolve borrower - either registered player or plain text name
        $borrower = null;
        $borrowerName = null;

        if ($message->borrowerPlayerId !== null) {
            $borrower = $this->playerRepository->get($message->borrowerPlayerId);
        } else {
            $borrowerName = $message->borrowerName;
        }

        // Check if puzzle is already lent by this owner
        $existingLent = $this->lentPuzzleRepository->findByOwnerAndPuzzle($owner, $puzzle);

        if ($existingLent !== null) {
            // Get previous holder info for transfer record
            $previousHolder = $existingLent->currentHolderPlayer;
            $previousHolderName = $existingLent->currentHolderName;

            // Already lent, update the holder
            $existingLent->changeCurrentHolder($borrower, $borrowerName);
            $existingLent->changeNotes($message->notes);

            // Record the transfer (pass)
            $transfer = new LentPuzzleTransfer(
                Uuid::uuid7(),
                $existingLent,
                $previousHolder,
                $previousHolderName,
                $borrower,
                $borrowerName,
                new DateTimeImmutable(),
                TransferType::Pass,
            );

            $this->lentPuzzleTransferRepository->save($transfer);

            if ($borrower !== null) {
                $this->messageBus->dispatch(new PuzzleBorrowed(
                    $borrower->id->toString(),
                    $puzzle->id->toString(),
                ));
            }

            return;
        }

        $now = new DateTimeImmutable();

        $lentPuzzle = new LentPuzzle(
            Uuid::uuid7(),
            $puzzle,
            $owner,
            null, // ownerName is null since owner is always the logged-in user (registered)
            $borrower,
            $borrowerName,
            $now,
            $message->notes,
        );

        $this->lentPuzzleRepository->save($lentPuzzle);

        // Record the initial transfer
        $transfer = new LentPuzzleTransfer(
            Uuid::uuid7(),
            $lentPuzzle,
            null, // from player (null indicates initial lend)
            null, // from player name
            $borrower,
            $borrowerName,
            $now,
            TransferType::InitialLend,
        );

        $this->lentPuzzleTransferRepository->save($transfer);

        if ($borrower !== null) {
            $this->messageBus->dispatch(new PuzzleBorrowed(
                $borrower->id->toString(),
                $puzzle->id->toString(),
            ));
        }
    }
}
