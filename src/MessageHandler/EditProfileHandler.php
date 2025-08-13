<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\Filesystem;
use Liip\ImagineBundle\Message\WarmupCache;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\EditProfile;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class EditProfileHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private Filesystem $filesystem,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(EditProfile $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $avatarPath = $player->avatar;

        if ($message->avatar !== null) {
            $extension = $message->avatar->guessExtension();
            $timestamp = $this->clock->now()->getTimestamp();
            $avatarPath = "avatars/{$player->id->toString()}-$timestamp.$extension";

            // Stream is better because it is memory safe
            $stream = fopen($message->avatar->getPathname(), 'rb');
            $this->filesystem->writeStream($avatarPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $this->messageBus->dispatch(
                new WarmupCache($avatarPath),
            );
        }

        $player->changeProfile(
            name: $message->name,
            email: $message->email,
            city: $message->city,
            country: $message->country,
            avatar: $avatarPath,
            bio: $message->bio,
            facebook: $message->facebook,
            instagram: $message->instagram,
        );
    }
}
