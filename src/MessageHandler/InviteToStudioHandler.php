<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\StudioInvite;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Exceptions\StudioInviteRefused;
use SpeedPuzzling\Web\Message\InviteToStudio;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\StudioInviteRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Invite to the studio" (docs/features/online-competitions/contracts/phase-1b.md §4): a new invitation for a
 * participant of the round - refused (StudioInviteRefused, nothing written) when the round's live video is off, when the
 * participant is not entered in the round, or while another invitation of theirs in the round is open (waiting for an
 * answer, or on air). Their latest invitation that ran out unanswered is written as expired on the way.
 *
 * Returns the new invitation - the controller tells the participant's phone once it is committed.
 */
#[AsMessageHandler]
readonly final class InviteToStudioHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private OnlineRoundRepository $onlineRoundRepository,
        private CompetitionParticipantRepository $competitionParticipantRepository,
        private CompetitionParticipantRoundRepository $competitionParticipantRoundRepository,
        private StudioInviteRepository $studioInviteRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound the round is not one of the competition's
     * @throws OnlineRoundNotFound the round does not use online timing
     * @throws CompetitionParticipantNotFound not a participant of the competition, or a removed one
     * @throws StudioInviteRefused
     */
    public function __invoke(InviteToStudio $message): StudioInvite
    {
        $round = $this->competitionRoundRepository->get($message->roundId);
        $roundId = $round->id->toString();

        if ($round->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        $onlineRound = $this->onlineRoundRepository->ofRound($roundId) ?? throw new OnlineRoundNotFound();

        if ($onlineRound->videoEnabled === false) {
            throw StudioInviteRefused::because(StudioInviteRefused::VIDEO_UNAVAILABLE);
        }

        $participant = $this->competitionParticipantRepository->getActiveOfCompetition($message->competitionId, $message->participantId);
        $participantId = $participant->id->toString();

        if ($this->competitionParticipantRoundRepository->findOfParticipantInRound($participantId, $roundId) === null) {
            throw StudioInviteRefused::because(StudioInviteRefused::NOT_ENTERED);
        }

        $now = $this->clock->now();
        $latest = $this->studioInviteRepository->latestOf($roundId, $participantId);

        if ($latest !== null) {
            if ($latest->isOpenAt($now)) {
                throw StudioInviteRefused::alreadyInvited($latest);
            }

            $latest->expire();
        }

        $invite = new StudioInvite(
            id: Uuid::uuid7(),
            round: $round,
            participant: $participant,
            invitedBy: $this->playerRepository->get($message->actingPlayerId),
            invitedAt: $now,
        );
        $this->studioInviteRepository->save($invite);

        return $invite;
    }
}
