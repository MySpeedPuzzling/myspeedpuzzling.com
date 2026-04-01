<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\AcceptFairUsePolicy;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AcceptFairUsePolicyHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(AcceptFairUsePolicy $message): void
    {
        $player = $this->entityManager->find(Player::class, $message->playerId);
        assert($player !== null);

        if ($player->hasFairUsePolicyAccepted()) {
            return;
        }

        $player->acceptFairUsePolicy();
        $this->entityManager->flush();
    }
}
