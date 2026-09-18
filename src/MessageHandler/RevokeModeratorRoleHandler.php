<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Message\RevokeModeratorRole;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RevokeModeratorRoleHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RevokeModeratorRole $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        if ($player->isModerator() === false) {
            return;
        }

        $player->revokeModeratorRole();

        // In-app only, deliberately no e-mail: parting ways deserves a personal
        // message from an admin, not a template
        $this->entityManager->persist(new Notification(
            Uuid::uuid7(),
            $player,
            NotificationType::ModeratorRoleRevoked,
            $this->clock->now(),
        ));
    }
}
