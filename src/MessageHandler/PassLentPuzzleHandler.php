<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\LentPuzzleTransfer;
use SpeedPuzzling\Web\Events\LendingTransferCompleted;
use SpeedPuzzling\Web\Exceptions\LentPuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\PassLentPuzzle;
use SpeedPuzzling\Web\Repository\LentPuzzleRepository;
use SpeedPuzzling\Web\Repository\LentPuzzleTransferRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\TransferType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class PassLentPuzzleHandler
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
    public function __invoke(PassLentPuzzle $message): void
    {
        $lentPuzzle = $this->lentPuzzleRepository->get($message->lentPuzzleId);
        $currentHolder = $this->playerRepository->get($message->currentHolderPlayerId);

        // Resolve new holder - either registered player or plain text name
        $newHolder = null;
        $newHolderName = null;

        if ($message->newHolderPlayerId !== null) {
            $newHolder = $this->playerRepository->get($message->newHolderPlayerId);
        } else {
            $newHolderName = $message->newHolderName;
        }

        // Verify current holder is correct (either the actual holder or the owner)
        $isCurrentHolder = $lentPuzzle->currentHolderPlayer !== null
            && $lentPuzzle->currentHolderPlayer->id->equals($currentHolder->id);
        $isOwner = $lentPuzzle->ownerPlayer !== null
            && $lentPuzzle->ownerPlayer->id->equals($currentHolder->id);

        if (!$isCurrentHolder && !$isOwner) {
            throw new LentPuzzleNotFound();
        }

        // Get previous holder info for transfer record
        $previousHolder = $lentPuzzle->currentHolderPlayer;
        $previousHolderName = $lentPuzzle->currentHolderName;

        // Check if passing to owner = treat as return
        $isPassingToOwner = $newHolder !== null
            && $lentPuzzle->ownerPlayer !== null
            && $newHolder->id->equals($lentPuzzle->ownerPlayer->id);

        if ($isPassingToOwner) {
            // Behave as return - record transfer and delete the lent puzzle
            $transfer = new LentPuzzleTransfer(
                Uuid::uuid7(),
                $lentPuzzle,
                $previousHolder,
                $previousHolderName,
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
                $currentHolder->id,
                $previousHolder?->id,
                $lentPuzzle->ownerPlayer->id,
                $lentPuzzle->ownerPlayer->id,
            ));

            $this->lentPuzzleRepository->delete($lentPuzzle);
        } else {
            // Normal pass - record transfer and update current holder
            $transfer = new LentPuzzleTransfer(
                Uuid::uuid7(),
                $lentPuzzle,
                $previousHolder,
                $previousHolderName,
                $newHolder,
                $newHolderName,
                new DateTimeImmutable(),
                TransferType::Pass,
            );

            $this->lentPuzzleTransferRepository->save($transfer);

            $this->messageBus->dispatch(new LendingTransferCompleted(
                $transfer->id,
                $lentPuzzle->puzzle->id,
                TransferType::Pass,
                $currentHolder->id,
                $previousHolder?->id,
                $newHolder?->id,
                $lentPuzzle->ownerPlayer?->id,
            ));

            $lentPuzzle->changeCurrentHolder($newHolder, $newHolderName);
        }
    }
}
