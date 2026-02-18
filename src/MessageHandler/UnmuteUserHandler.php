<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ModerationAction;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\UnmuteUser;
use SpeedPuzzling\Web\Repository\ModerationActionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\ModerationActionType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class UnmuteUserHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private ModerationActionRepository $moderationActionRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(UnmuteUser $message): void
    {
        $targetPlayer = $this->playerRepository->get($message->targetPlayerId);
        $admin = $this->playerRepository->get($message->adminId);

        $targetPlayer->unmuteMessaging();

        $action = new ModerationAction(
            id: Uuid::uuid7(),
            targetPlayer: $targetPlayer,
            admin: $admin,
            actionType: ModerationActionType::MuteLifted,
            performedAt: new DateTimeImmutable(),
        );

        $this->moderationActionRepository->save($action);
    }
}
