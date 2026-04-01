<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PersonalAccessToken;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\CreatePersonalAccessToken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreatePersonalAccessTokenHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreatePersonalAccessToken $message): string
    {
        $player = $this->entityManager->find(Player::class, $message->playerId);
        assert($player !== null);

        $plainToken = 'msp_pat_' . bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $plainToken);
        $tokenPrefix = substr($plainToken, 0, 16);

        $now = new DateTimeImmutable();

        $token = new PersonalAccessToken(
            id: Uuid::fromString($message->tokenId),
            player: $player,
            name: $message->name,
            tokenHash: $tokenHash,
            tokenPrefix: $tokenPrefix,
            fairUsePolicyAcceptedAt: $now,
            createdAt: $now,
        );

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $plainToken;
    }
}
