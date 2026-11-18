<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantLinking;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\ParticipantLinkRequest;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Query\GetCompetitionOrganisers;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\NotificationType;

/**
 * The in-app notifications of participant linking (docs/features/competitions-management/participant-linking.md §9.2) -
 * no e-mail. Called by ParticipantLinks only, in the transaction of the change it tells about (persist only).
 *
 * Nobody is named but the reader: the participant's name is the organiser's record, a decline says no reason and no who.
 */
readonly final class ParticipantLinkNotifications
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private GetCompetitionOrganisers $getCompetitionOrganisers,
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * "People are waiting for you to confirm" (D16): every organiser of the event - its COMPETITION_EDIT team, an admin
     * only when on it - who has no unread one of this event yet. Never the claimant themselves.
     */
    public function requested(ParticipantLinkRequest $request, DateTimeImmutable $now): void
    {
        $competitionId = $request->competition->id->toString();

        foreach ($this->getCompetitionOrganisers->playerIds($competitionId) as $organiserId) {
            if ($request->player->id->toString() === $organiserId) {
                continue;
            }

            if ($this->notificationRepository->hasUnreadParticipantLinkRequested($organiserId, $competitionId)) {
                continue;
            }

            $this->notificationRepository->save(new Notification(
                id: Uuid::uuid7(),
                player: $this->playerRepository->get($organiserId),
                type: NotificationType::ParticipantLinkRequested,
                notifiedAt: $now,
                targetParticipantLinkRequest: $request,
            ));
        }
    }

    public function approved(ParticipantLinkRequest $request, DateTimeImmutable $now): void
    {
        $this->toClaimant($request, NotificationType::ParticipantLinkApproved, $now);
    }

    /**
     * The organiser's "no" - and a request superseded by somebody else's link of the row (R10: the same neutral text)
     */
    public function declined(ParticipantLinkRequest $request, DateTimeImmutable $now): void
    {
        $this->toClaimant($request, NotificationType::ParticipantLinkDeclined, $now);
    }

    /**
     * "You are on the participant list of %event% as %name%" + "Not me" (D8, §6.6)
     */
    public function listedYou(CompetitionParticipant $participant, Player $player, DateTimeImmutable $now): void
    {
        $this->notificationRepository->save(new Notification(
            id: Uuid::uuid7(),
            player: $player,
            type: NotificationType::ParticipantListedYou,
            notifiedAt: $now,
            targetCompetitionParticipant: $participant,
        ));
    }

    private function toClaimant(ParticipantLinkRequest $request, NotificationType $type, DateTimeImmutable $now): void
    {
        $this->notificationRepository->save(new Notification(
            id: Uuid::uuid7(),
            player: $request->player,
            type: $type,
            notifiedAt: $now,
            targetParticipantLinkRequest: $request,
        ));
    }
}
