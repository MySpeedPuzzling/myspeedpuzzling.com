<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantAlreadyConnectedToDifferentPlayer;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\RegistrationNotOpen;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Query\CountCompetitionRegistrations;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\CompetitionRegistrationMailer;
use SpeedPuzzling\Web\Services\CompetitionSelfJoin;
use SpeedPuzzling\Web\Value\RegistrationAvailability;
use SpeedPuzzling\Web\Value\RegistrationEmail;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "I'm going" (docs/features/competitions-management/participants.md). On an event that manages registration
 * (registration.md) a new spot - joining yourself, or again after cancelling - is a registration: only while its window
 * is open, reserved under the capacity, waitlisted when full (first come, first served - JoinCompetition takes turns with
 * every other participant write of the event). Picking your name from the organiser's list is no new spot: it always
 * works and keeps the row's status - the organiser holds that spot. Nothing at all on an event that is not publicly
 * visible - a draft, waiting for approval or rejected (docs/features/organizations/README.md, P17).
 */
#[AsMessageHandler]
readonly final class JoinCompetitionHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private CompetitionRepository $competitionRepository,
        private PlayerRepository $playerRepository,
        private GetCompetitionParticipants $getCompetitionParticipants,
        private ClockInterface $clock,
        private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        private CountCompetitionRegistrations $countCompetitionRegistrations,
        private CompetitionRegistrationMailer $registrationMailer,
        private CompetitionSelfJoin $competitionSelfJoin,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     * @throws CompetitionParticipantNotFound
     * @throws CompetitionParticipantAlreadyConnectedToDifferentPlayer
     * @throws RegistrationNotOpen
     */
    public function __invoke(JoinCompetition $message): void
    {
        // Only a publicly visible event can be joined (docs/features/organizations/README.md, P17): a draft, an event
        // waiting for approval or a rejected one has no public page - picking a name from its list included
        if ($this->isCompetitionPubliclyVisible->check($message->competitionId) === false) {
            throw new CompetitionNotFound();
        }

        $player = $this->playerRepository->get($message->playerId);

        if ($message->participantId !== null) {
            $participant = $this->participantRepository->get($message->participantId);

            // Validate everything before touching the player's current rows: a rolled-back handler
            // leaves its changed entities in the entity manager, and the next flush of the same
            // request would still write them
            if ($participant->competition->id->toString() !== $message->competitionId || $participant->isDeleted()) {
                throw new CompetitionParticipantNotFound();
            }

            if ($participant->player !== null && $participant->player->id->equals($player->id) === false) {
                throw new CompetitionParticipantAlreadyConnectedToDifferentPlayer();
            }

            $this->competitionSelfJoin->releaseOtherParticipants($message->competitionId, $player, keep: $participant);
            $participant->connect($player, $this->clock->now());

            return;
        }

        if ($this->getCompetitionParticipants->isPlayerSelfJoined($message->competitionId, $message->playerId)) {
            return;
        }

        $competition = $this->competitionRepository->get($message->competitionId);

        // A new spot of a managed event: decided before anything changes
        $registration = $competition->registrationManaged ? $this->newRegistrationStatus($competition) : null;

        $participant = $this->competitionSelfJoin->takeOwnRow($competition, $player);

        if ($registration !== null) {
            // A registration made again after cancelling starts fresh - at the end of the waitlist, not paid
            $participant->register($registration['status'], $this->clock->now());

            $this->registrationMailer->send(
                $participant,
                $registration['status'] === RegistrationStatus::Waitlisted ? RegistrationEmail::Waitlisted : RegistrationEmail::Reserved,
                $registration['waitlistPosition'],
            );
        }

        $this->competitionSelfJoin->handOwnLink($participant);
    }

    /**
     * @return array{status: RegistrationStatus, waitlistPosition: null|int}
     *
     * @throws RegistrationNotOpen
     */
    private function newRegistrationStatus(Competition $competition): array
    {
        // Publicly visible - checked on the way in
        $availability = $competition->registrationAvailability($this->clock->now(), true);

        if ($availability !== RegistrationAvailability::Open) {
            throw new RegistrationNotOpen($availability);
        }

        // The player holds no row that counts yet: none self-joined (returned above), a soft-deleted one is not
        // counted, and an organiser's row they let go of keeps holding its spot for the organiser
        $counts = $this->countCompetitionRegistrations->of($competition->id->toString());

        if ($counts->isFull($competition->capacity)) {
            return ['status' => RegistrationStatus::Waitlisted, 'waitlistPosition' => $counts->waitlisted + 1];
        }

        return ['status' => RegistrationStatus::Reserved, 'waitlistPosition' => null];
    }
}
