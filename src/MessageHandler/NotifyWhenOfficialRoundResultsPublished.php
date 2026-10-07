<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Events\OfficialRoundResultsPublished;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetOfficialResultRecipients;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Your official result is out" - an in-app notification, no e-mail, to every player with a finished result in the
 * round, once per round: the event is recorded only on the round's first publish, and a retry skips who has it already.
 * Results taken off the page again before this runs tell nobody (the notification would lead to an empty page).
 */
#[AsMessageHandler]
readonly final class NotifyWhenOfficialRoundResultsPublished
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private GetOfficialResultRecipients $getOfficialResultRecipients,
        private NotificationRepository $notificationRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(OfficialRoundResultsPublished $event): void
    {
        try {
            $round = $this->roundRepository->get($event->roundId->toString());
        } catch (CompetitionRoundNotFound) {
            // Handled asynchronously - the round may be gone by now
            return;
        }

        if ($round->areResultsPublished() === false) {
            return;
        }

        $alreadyNotified = array_flip($this->notificationRepository->playerIdsNotifiedAboutRound($round));
        $now = $this->clock->now();

        foreach ($this->getOfficialResultRecipients->forRound($round->id->toString()) as $playerId) {
            if (isset($alreadyNotified[$playerId])) {
                continue;
            }

            try {
                $player = $this->playerRepository->get($playerId);
            } catch (PlayerNotFound) {
                continue;
            }

            $this->notificationRepository->save(new Notification(
                id: Uuid::uuid7(),
                player: $player,
                type: NotificationType::OfficialResultPublished,
                notifiedAt: $now,
                targetCompetitionRound: $round,
            ));
        }
    }
}
