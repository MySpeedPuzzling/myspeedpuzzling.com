<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\RegisterUserToPlay;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RegisterUserToPlayHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RegisterUserToPlay $message): void
    {
        $name = $message->name;
        // Obviously, name is either email or not filled
        if ($message->name === $message->email) {
            $name = null;
        }

        $player = new Player(
            Uuid::uuid7(),
            $message->userId,
            $message->email,
            $name,
            null,
            null,
            new \DateTimeImmutable(),
        );

        $this->entityManager->persist($player);
    }
}
