<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RefuseParticipantClaimInvitations;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Don't send me more invitations for this event" (docs/features/competitions-management/participant-linking.md §9.1,
 * R8): the person said no for this participant row - no claim invitation for it any more, whoever sends it. A removed
 * row too (it may come back). Saying it again changes nothing. An unknown participant or one of another event is a 404
 * (CompetitionParticipantNotFound).
 */
#[AsMessageHandler]
readonly final class RefuseParticipantClaimInvitationsHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RefuseParticipantClaimInvitations $message): void
    {
        $participant = $this->participantRepository->getOfCompetition($message->competitionId, $message->participantId);
        $participant->refuseClaimInvitations($this->clock->now());
    }
}
