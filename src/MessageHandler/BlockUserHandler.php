<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserBlock;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\BlockUser;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PrivateProfileViewerRepository;
use SpeedPuzzling\Web\Repository\UserBlockRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class BlockUserHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private UserBlockRepository $userBlockRepository,
        private PrivateProfileViewerRepository $privateProfileViewerRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(BlockUser $message): void
    {
        $blocker = $this->playerRepository->get($message->blockerId);
        $blocked = $this->playerRepository->get($message->blockedId);

        if ($blocker->id->equals($blocked->id)) {
            return;
        }

        // Idempotent - and an admin-imposed block stays what it is (see docs/features/player-blocklist.md)
        $existingBlock = $this->userBlockRepository->findByBlockerAndBlocked($blocker, $blocked);
        if ($existingBlock !== null) {
            return;
        }

        // Only the blocker's side: touching the blocked player's favourites would give the block away
        $blocker->discardFavoritePlayerId($blocked->id->toString());

        // Whoever I block no longer belongs on my private profile's allow list
        $allowed = $this->privateProfileViewerRepository->findByOwnerAndViewer($blocker, $blocked);
        if ($allowed !== null) {
            $this->privateProfileViewerRepository->remove($allowed);
        }

        $userBlock = new UserBlock(
            id: Uuid::uuid7(),
            blocker: $blocker,
            blocked: $blocked,
            blockedAt: $this->clock->now(),
        );

        $this->userBlockRepository->save($userBlock);
    }
}
