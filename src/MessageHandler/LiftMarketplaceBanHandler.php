<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ModerationAction;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\LiftMarketplaceBan;
use SpeedPuzzling\Web\Repository\ModerationActionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\ModerationActionType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class LiftMarketplaceBanHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private ModerationActionRepository $moderationActionRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(LiftMarketplaceBan $message): void
    {
        $targetPlayer = $this->playerRepository->get($message->targetPlayerId);
        $admin = $this->playerRepository->get($message->adminId);

        $targetPlayer->liftMarketplaceBan();

        $action = new ModerationAction(
            id: Uuid::uuid7(),
            targetPlayer: $targetPlayer,
            admin: $admin,
            actionType: ModerationActionType::BanLifted,
            performedAt: $this->clock->now(),
        );

        $this->moderationActionRepository->save($action);
    }
}
