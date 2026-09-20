<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Events\PuzzlingTeamRenamed;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzlingTeamNotFound;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzlingTeamRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The name of a pair/team is shared and public, and any registered member may change it - so the
 * other members get told who did (docs/features/pairs-and-teams/README.md, D4).
 */
#[AsMessageHandler]
readonly final class NotifyWhenPuzzlingTeamRenamed
{
    public function __construct(
        private PuzzlingTeamRepository $puzzlingTeamRepository,
        private PlayerRepository $playerRepository,
        private NotificationRepository $notificationRepository,
        private GetUserBlocks $getUserBlocks,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PuzzlingTeamRenamed $event): void
    {
        try {
            $team = $this->puzzlingTeamRepository->get($event->teamId->toString());
            $renamedBy = $this->playerRepository->get($event->renamedByPlayerId->toString());
        } catch (PuzzlingTeamNotFound | PlayerNotFound) {
            // The event is handled asynchronously - the team or the player may be gone by now
            return;
        }

        // Nothing is created about the renaming player for a member who blocks them
        $blockerIds = $this->getUserBlocks->blockersOf([$renamedBy->id->toString()]);

        foreach ($this->puzzlingTeamRepository->memberPlayerIds($team) as $memberId) {
            if ($memberId === $renamedBy->id->toString() || in_array($memberId, $blockerIds, true)) {
                continue;
            }

            try {
                $member = $this->playerRepository->get($memberId);
            } catch (PlayerNotFound) {
                continue;
            }

            // Three attempts at the right name are one piece of news - the notification shows the current name
            if ($this->notificationRepository->hasUnreadTeamRenamedNotification($member, $team, $renamedBy)) {
                continue;
            }

            $this->notificationRepository->save(new Notification(
                Uuid::uuid7(),
                $member,
                NotificationType::PuzzlingTeamRenamed,
                $this->clock->now(),
                actorPlayer: $renamedBy,
                targetPuzzlingTeam: $team,
            ));
        }
    }
}
