<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Query\GetPrivateProfileViewers;
use SpeedPuzzling\Web\Query\GetSubscribedPlayers;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class NotifyWhenPuzzleSolved
{
    public function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PlayerRepository $playerRepository,
        private GetSubscribedPlayers $getSubscribedPlayers,
        private GetPrivateProfileViewers $getPrivateProfileViewers,
        private GetUserBlocks $getUserBlocks,
        private NotificationRepository $notificationRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PuzzleSolved $event): void
    {
        $solvingTime = $this->puzzleSolvingTimeRepository->get($event->puzzleSolvingTimeId->toString());

        // Followers of a public player get notified; of a private player only those the player
        // put on their allow list (docs/features/private-profile-allow-list.md)
        $publicPlayerIds = [];
        $subscribedPlayerIds = [];

        foreach ($solvingTime->memberPlayerIds() as $memberPlayerId) {
            if ($this->playerRepository->get($memberPlayerId)->isPrivate === false) {
                $publicPlayerIds[] = $memberPlayerId;

                continue;
            }

            $subscribedPlayerIds = [...$subscribedPlayerIds, ...$this->getPrivateProfileViewers->followersAllowedBy($memberPlayerId)];
        }

        // One subscriber gets one notification, however many of the team they follow
        if ($publicPlayerIds !== []) {
            $subscribedPlayerIds = [...$subscribedPlayerIds, ...$this->getSubscribedPlayers->ofPlayers($publicPlayerIds)];
        }

        $subscribedPlayerIds = array_values(array_unique($subscribedPlayerIds));

        if ($subscribedPlayerIds === []) {
            return;
        }

        // Nothing is created about a player for someone who blocks them - the notification shows
        // every member of the group, private or not
        $subscribedPlayerIds = array_values(array_diff(
            $subscribedPlayerIds,
            $this->getUserBlocks->blockersOf($solvingTime->memberPlayerIds()),
        ));

        if ($subscribedPlayerIds === []) {
            return;
        }

        $this->notificationRepository->addForSolvingTime(
            $subscribedPlayerIds,
            NotificationType::SubscribedPlayerAddedTime,
            $solvingTime->id,
            $this->clock->now(),
        );
    }
}
