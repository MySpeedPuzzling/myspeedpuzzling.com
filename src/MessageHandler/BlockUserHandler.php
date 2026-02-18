<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserBlock;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\BlockUser;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserBlockRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class BlockUserHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private UserBlockRepository $userBlockRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(BlockUser $message): void
    {
        $blocker = $this->playerRepository->get($message->blockerId);
        $blocked = $this->playerRepository->get($message->blockedId);

        // Check if block already exists - idempotent
        $existingBlock = $this->userBlockRepository->findByBlockerAndBlocked($blocker, $blocked);
        if ($existingBlock !== null) {
            return;
        }

        $userBlock = new UserBlock(
            id: Uuid::uuid7(),
            blocker: $blocker,
            blocked: $blocked,
            blockedAt: new DateTimeImmutable(),
        );

        $this->userBlockRepository->save($userBlock);
    }
}
