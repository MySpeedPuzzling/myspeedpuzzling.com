<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\LentPuzzle;
use SpeedPuzzling\Web\Entity\LentPuzzleTransfer;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\BorrowPuzzleFromPlayer;
use SpeedPuzzling\Web\Repository\LentPuzzleRepository;
use SpeedPuzzling\Web\Repository\LentPuzzleTransferRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\TransferType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class BorrowPuzzleFromPlayerHandler
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
    public function __invoke(BorrowPuzzleFromPlayer $message): void
    {
        $borrower = $this->playerRepository->get($message->borrowerPlayerId);
        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        // Resolve owner - either registered player or plain text name
        $owner = null;
        $ownerName = null;

        if ($message->ownerPlayerId !== null) {
            $owner = $this->playerRepository->get($message->ownerPlayerId);
        } else {
            $ownerName = $message->ownerName;
        }

        // Check if puzzle is already lent by this owner
        $existingLent = $owner !== null
            ? $this->lentPuzzleRepository->findByOwnerAndPuzzle($owner, $puzzle)
            : ($ownerName !== null ? $this->lentPuzzleRepository->findByOwnerNameAndPuzzle($ownerName, $puzzle) : null);

        if ($existingLent !== null) {
            // Get previous holder info for transfer record
            $previousHolder = $existingLent->currentHolderPlayer;
            $previousHolderName = $existingLent->currentHolderName;

            // Already lent, update the holder to be the borrower
            $existingLent->changeCurrentHolder($borrower, null);
            $existingLent->changeNotes($message->notes);

            // Record the transfer (pass from previous holder to this borrower)
            $transfer = new LentPuzzleTransfer(
                Uuid::uuid7(),
                $existingLent,
                $previousHolder,
                $previousHolderName,
                $borrower,
                null, // borrower is always registered, no name needed
                new DateTimeImmutable(),
                TransferType::Pass,
            );

            $this->lentPuzzleTransferRepository->save($transfer);

            return;
        }

        $now = new DateTimeImmutable();

        // Create new lent puzzle record with owner as owner and borrower as current holder
        $lentPuzzle = new LentPuzzle(
            Uuid::uuid7(),
            $puzzle,
            $owner,
            $ownerName,
            $borrower,
            null, // borrower is always registered, no name needed
            $now,
            $message->notes,
        );

        $this->lentPuzzleRepository->save($lentPuzzle);

        // Record the initial transfer (borrower self-reported they borrowed from owner)
        $transfer = new LentPuzzleTransfer(
            Uuid::uuid7(),
            $lentPuzzle,
            null, // from player (null indicates initial lend)
            null, // from player name
            $borrower,
            null, // borrower is always registered, no name needed
            $now,
            TransferType::InitialLend,
        );

        $this->lentPuzzleTransferRepository->save($transfer);
    }
}
