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
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\OfficialResultNoticeRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Your official result is out" - an in-app notification, no e-mail, to every player with a finished result in the
 * round. Each player hears about a round once, ever: the OfficialResultNotice marker is claimed in the same
 * transaction as the notification, so runs for a republish, a late result and an approval never tell anybody twice -
 * also when they run at the same moment.
 *
 * Runs whenever there may be somebody new to tell (OfficialRoundResultsPublished: every publish, a finished result
 * recorded on a published round, the event approved). It tells nobody while the results are not on the page or the
 * event is not publicly visible (the link would lead to an empty or missing page) - the next publish or the approval
 * runs it again.
 */
#[AsMessageHandler]
readonly final class NotifyWhenOfficialRoundResultsPublished
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private GetOfficialResultRecipients $getOfficialResultRecipients,
        private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        private OfficialResultNoticeRepository $noticeRepository,
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

        if ($this->isCompetitionPubliclyVisible->check($round->competition->id->toString()) === false) {
            return;
        }

        $roundId = $round->id->toString();
        $now = $this->clock->now();

        foreach ($this->getOfficialResultRecipients->forRound($roundId) as $playerId) {
            try {
                $player = $this->playerRepository->get($playerId);
            } catch (PlayerNotFound) {
                continue;
            }

            if ($this->noticeRepository->claim($playerId, $roundId, $now) === false) {
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
