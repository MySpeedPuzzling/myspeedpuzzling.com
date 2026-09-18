<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Events\GroupSolvingTimeEdited;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class NotifyWhenGroupSolvingTimeEdited
{
    public function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PlayerRepository $playerRepository,
        private NotificationRepository $notificationRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(GroupSolvingTimeEdited $event): void
    {
        try {
            $solvingTime = $this->puzzleSolvingTimeRepository->get($event->puzzleSolvingTimeId->toString());
            $editedBy = $this->playerRepository->get($event->editedByPlayerId->toString());
        } catch (PuzzleSolvingTimeNotFound | PlayerNotFound) {
            // The event is handled asynchronously - the time or the editor may be gone by now
            return;
        }

        // Members before and after the edit: whoever the edit removed from the group should know as well
        $memberIds = array_unique([...$event->memberPlayerIdsBeforeEdit, ...$solvingTime->memberPlayerIds()]);

        foreach ($memberIds as $memberId) {
            if ($memberId === $editedBy->id->toString()) {
                continue;
            }

            try {
                $member = $this->playerRepository->get($memberId);
            } catch (PlayerNotFound) {
                continue;
            }

            // Fixing a typo in three attempts is one piece of news, not three
            if ($this->notificationRepository->hasUnreadGroupEditNotification($member, $solvingTime, $editedBy)) {
                continue;
            }

            $this->notificationRepository->save(new Notification(
                Uuid::uuid7(),
                $member,
                NotificationType::GroupSolvingTimeEdited,
                $this->clock->now(),
                targetSolvingTime: $solvingTime,
                actorPlayer: $editedBy,
            ));
        }
    }
}
