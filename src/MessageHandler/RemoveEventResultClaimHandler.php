<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Exceptions\EventResultClaimRefused;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Message\RemoveEventResultClaim;
use SpeedPuzzling\Web\Query\GetCompetitionPermissions;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\ResultClaimLogRepository;
use SpeedPuzzling\Web\Services\EventResultsImport\EventResultSlotRelease;
use SpeedPuzzling\Web\Value\EventResultsImport\EventResultClaimRefusal;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultClaimAction;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Not on our team" (docs/features/event-results-import/README.md "Gates"): another claimer of the result, the
 * organizer's team of its event or an admin takes a player's claim back. The spot is anonymous again, the removed
 * player is told (without who did it), and a player removed twice can no longer claim - an admin lets them again.
 */
#[AsMessageHandler]
readonly final class RemoveEventResultClaimHandler
{
    /**
     * Removed this many times = no more claiming (D6).
     */
    public const int REMOVALS_UNTIL_BLOCKED = 2;

    public function __construct(
        private PuzzleSolvingTimeRepository $solvingTimeRepository,
        private PlayerRepository $playerRepository,
        private ResultClaimLogRepository $resultClaimLogRepository,
        private NotificationRepository $notificationRepository,
        private GetCompetitionPermissions $getCompetitionPermissions,
        private EventResultSlotRelease $eventResultSlotRelease,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     * @throws PlayerNotFound
     * @throws EventResultClaimRefused
     */
    public function __invoke(RemoveEventResultClaim $message): void
    {
        $time = $this->solvingTimeRepository->get($message->timeId);
        $player = $this->playerRepository->get($message->playerId);
        $by = $this->playerRepository->get($message->byPlayerId);

        if ($time->isImportedFromEvent() === false) {
            throw new EventResultClaimRefused(EventResultClaimRefusal::NotImported);
        }

        if ($this->mayRemove($time, $player, $by) === false) {
            throw new EventResultClaimRefused(EventResultClaimRefusal::NotAllowed);
        }

        $removalsBefore = $this->resultClaimLogRepository->countRemovalsOf($player->id);
        $reason = $message->reason !== null && trim($message->reason) !== '' ? mb_substr(trim($message->reason), 0, 500) : null;

        $this->eventResultSlotRelease->release($time, $player, ResultClaimAction::Removed, $by, $reason);

        $now = $this->clock->now();

        if ($removalsBefore + 1 >= self::REMOVALS_UNTIL_BLOCKED) {
            $player->blockEventClaims($now);
        }

        $this->notificationRepository->save(new Notification(
            Uuid::uuid7(),
            $player,
            NotificationType::EventResultClaimRemoved,
            $now,
            targetSolvingTime: $time,
            actorPlayer: $by,
        ));
    }

    /**
     * Another claimer of the same result (they know who was there), the organizer's team of the event, an admin. Never
     * the player themselves - that is "Remove from my profile".
     */
    private function mayRemove(PuzzleSolvingTime $time, Player $player, Player $by): bool
    {
        if ($by->id->equals($player->id)) {
            return false;
        }

        if ($by->isAdmin) {
            return true;
        }

        if (in_array($by->id->toString(), $time->memberPlayerIds(), true)) {
            return true;
        }

        $competition = $time->competition;

        return $competition !== null
            && $this->getCompetitionPermissions->forPlayer($by->id->toString())->canEditCompetition($competition->id->toString());
    }
}
