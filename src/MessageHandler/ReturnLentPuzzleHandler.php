<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\LentPuzzleTransfer;
use SpeedPuzzling\Web\Events\LendingTransferCompleted;
use SpeedPuzzling\Web\Exceptions\LentPuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ReturnLentPuzzle;
use SpeedPuzzling\Web\Repository\LentPuzzleRepository;
use SpeedPuzzling\Web\Repository\LentPuzzleTransferRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\TransferType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class ReturnLentPuzzleHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private LentPuzzleRepository $lentPuzzleRepository,
        private LentPuzzleTransferRepository $lentPuzzleTransferRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws LentPuzzleNotFound
     */
    public function __invoke(ReturnLentPuzzle $message): void
    {
        $lentPuzzle = $this->lentPuzzleRepository->get($message->lentPuzzleId);
        $actingPlayer = $this->playerRepository->get($message->actingPlayerId);

        // Verify the acting player is either the current holder or the owner
        $isCurrentHolder = $lentPuzzle->currentHolderPlayer !== null
            && $lentPuzzle->currentHolderPlayer->id->equals($actingPlayer->id);
        $isOwner = $lentPuzzle->ownerPlayer !== null
            && $lentPuzzle->ownerPlayer->id->equals($actingPlayer->id);

        if (!$isCurrentHolder && !$isOwner) {
            throw new LentPuzzleNotFound();
        }

        // Record the return transfer
        $transfer = new LentPuzzleTransfer(
            Uuid::uuid7(),
            $lentPuzzle,
            $lentPuzzle->currentHolderPlayer,
            $lentPuzzle->currentHolderName,
            $lentPuzzle->ownerPlayer,
            $lentPuzzle->ownerName,
            new DateTimeImmutable(),
            TransferType::Return,
        );

        $this->lentPuzzleTransferRepository->save($transfer);

        $this->messageBus->dispatch(new LendingTransferCompleted(
            $transfer->id,
            $lentPuzzle->puzzle->id,
            TransferType::Return,
            $actingPlayer->id,
            $lentPuzzle->currentHolderPlayer?->id,
            $lentPuzzle->ownerPlayer?->id,
            $lentPuzzle->ownerPlayer?->id,
        ));

        // Delete the lent puzzle record (puzzle is returned to owner)
        $this->lentPuzzleRepository->delete($lentPuzzle);
    }
}
