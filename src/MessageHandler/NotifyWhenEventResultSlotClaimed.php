<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Events\EventResultSlotClaimed;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Ann joined Puzzle Pals" to everybody who claimed a spot of the same imported event result before - they were there,
 * so they see a wrong claim first and can say "Not on our team" (docs/features/event-results-import/README.md "Gates").
 */
#[AsMessageHandler]
readonly final class NotifyWhenEventResultSlotClaimed
{
    public function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PlayerRepository $playerRepository,
        private NotificationRepository $notificationRepository,
        private GetUserBlocks $getUserBlocks,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(EventResultSlotClaimed $event): void
    {
        try {
            $solvingTime = $this->puzzleSolvingTimeRepository->get($event->puzzleSolvingTimeId->toString());
            $claimer = $this->playerRepository->get($event->claimedByPlayerId->toString());
        } catch (PuzzleSolvingTimeNotFound | PlayerNotFound) {
            // Handled asynchronously - the result or the claimer may be gone by now
            return;
        }

        $claimerId = $claimer->id->toString();
        $memberIds = $solvingTime->memberPlayerIds();

        // Released or removed meanwhile: nobody is told about a claim that no longer stands
        if (in_array($claimerId, $memberIds, true) === false) {
            return;
        }

        // Nothing is created about the claimer for a member who blocks them
        $blockerIds = $this->getUserBlocks->blockersOf([$claimerId]);
        $now = $this->clock->now();

        foreach ($memberIds as $memberId) {
            if ($memberId === $claimerId || in_array($memberId, $blockerIds, true)) {
                continue;
            }

            try {
                $member = $this->playerRepository->get($memberId);
            } catch (PlayerNotFound) {
                continue;
            }

            $this->notificationRepository->save(new Notification(
                Uuid::uuid7(),
                $member,
                NotificationType::EventResultClaimed,
                $now,
                targetSolvingTime: $solvingTime,
                actorPlayer: $claimer,
            ));
        }
    }
}
