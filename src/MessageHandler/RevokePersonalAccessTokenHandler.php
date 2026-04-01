<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\PersonalAccessToken;
use SpeedPuzzling\Web\Message\RevokePersonalAccessToken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RevokePersonalAccessTokenHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RevokePersonalAccessToken $message): void
    {
        $token = $this->entityManager->find(PersonalAccessToken::class, $message->tokenId);

        if ($token === null) {
            return;
        }

        if ($token->player->id->toString() !== $message->playerId) {
            return;
        }

        $token->revoke();
        $this->entityManager->flush();
    }
}
