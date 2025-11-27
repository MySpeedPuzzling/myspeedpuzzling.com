<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\LentPuzzleTransfer;
use SpeedPuzzling\Web\Exceptions\LentPuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\PassLentPuzzle;
use SpeedPuzzling\Web\Repository\LentPuzzleRepository;
use SpeedPuzzling\Web\Repository\LentPuzzleTransferRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\TransferType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class PassLentPuzzleHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private LentPuzzleRepository $lentPuzzleRepository,
        private LentPuzzleTransferRepository $lentPuzzleTransferRepository,
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
        $newHolder = $this->playerRepository->get($message->newHolderPlayerId);

        // Verify current holder is correct (either the actual holder or the owner)
        $isCurrentHolder = $lentPuzzle->currentHolderPlayer->id->equals($currentHolder->id);
        $isOwner = $lentPuzzle->ownerPlayer->id->equals($currentHolder->id);

        if (!$isCurrentHolder && !$isOwner) {
            throw new LentPuzzleNotFound();
        }

        // Record the transfer before changing holder
        $transfer = new LentPuzzleTransfer(
            Uuid::uuid7(),
            $lentPuzzle,
            $lentPuzzle->currentHolderPlayer,
            $newHolder,
            new DateTimeImmutable(),
            TransferType::Pass,
        );

        $this->lentPuzzleTransferRepository->save($transfer);

        // Update the current holder
        $lentPuzzle->changeCurrentHolder($newHolder);
    }
}
