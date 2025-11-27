<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\LentPuzzleTransfer;
use SpeedPuzzling\Web\Exceptions\LentPuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ReturnLentPuzzle;
use SpeedPuzzling\Web\Repository\LentPuzzleRepository;
use SpeedPuzzling\Web\Repository\LentPuzzleTransferRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\TransferType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ReturnLentPuzzleHandler
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
    public function __invoke(ReturnLentPuzzle $message): void
    {
        $lentPuzzle = $this->lentPuzzleRepository->get($message->lentPuzzleId);
        $actingPlayer = $this->playerRepository->get($message->actingPlayerId);

        // Verify the acting player is either the current holder or the owner
        $isCurrentHolder = $lentPuzzle->currentHolderPlayer->id->equals($actingPlayer->id);
        $isOwner = $lentPuzzle->ownerPlayer->id->equals($actingPlayer->id);

        if (!$isCurrentHolder && !$isOwner) {
            throw new LentPuzzleNotFound();
        }

        // Record the return transfer
        $transfer = new LentPuzzleTransfer(
            Uuid::uuid7(),
            $lentPuzzle,
            $lentPuzzle->currentHolderPlayer,
            $lentPuzzle->ownerPlayer,
            new DateTimeImmutable(),
            TransferType::Return,
        );

        $this->lentPuzzleTransferRepository->save($transfer);

        // Delete the lent puzzle record (puzzle is returned to owner)
        $this->lentPuzzleRepository->delete($lentPuzzle);
    }
}
