<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\EditProfile;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditProfileHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(EditProfile $message): void
    {
        $player = $this->entityManager->find(Player::class, $message->playerId);

        if ($player === null) {
            throw new PlayerNotFound();
        }

        $player->changeProfile(
            name: $message->name,
            email: $message->email,
            city: $message->city,
            country: $message->country,
        );
    }
}
