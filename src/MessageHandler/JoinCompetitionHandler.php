<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\Player;
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
use SpeedPuzzling\Web\Services\OfficialResultsGuard;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RegistrationAvailability;
use SpeedPuzzling\Web\Value\RegistrationEmail;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "I'm going" (docs/features/competitions-management/participants.md). On an event that manages registration
 * (registration.md) a new spot - joining yourself, or again after cancelling - is a registration: only while the event
 * is publicly visible and its window is open, reserved under the capacity, waitlisted when full (first come, first
 * served - JoinCompetition takes turns with every other participant write of the event). Picking your name from the
 * organiser's list is no new spot: it always works and keeps the row's status - the organiser holds that spot.
 */
#[AsMessageHandler]
readonly final class JoinCompetitionHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private CompetitionRepository $competitionRepository,
        private PlayerRepository $playerRepository,
        private GetCompetitionParticipants $getCompetitionParticipants,
        private Connection $database,
        private ClockInterface $clock,
        private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        private CountCompetitionRegistrations $countCompetitionRegistrations,
        private CompetitionRegistrationMailer $registrationMailer,
        private OfficialResultsGuard $officialResultsGuard,
    ) {
    }

    /**
     * @throws CompetitionParticipantNotFound
     * @throws CompetitionParticipantAlreadyConnectedToDifferentPlayer
     * @throws RegistrationNotOpen
     */
    public function __invoke(JoinCompetition $message): void
    {
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

            $this->releaseOtherParticipants($message->competitionId, $player, keep: $participant);
            $participant->connect($player, $this->clock->now());

            return;
        }

        if ($this->getCompetitionParticipants->isPlayerSelfJoined($message->competitionId, $message->playerId)) {
            return;
        }

        $competition = $this->competitionRepository->get($message->competitionId);

        // A new spot of a managed event: decided before anything changes
        $registration = $competition->registrationManaged ? $this->newRegistrationStatus($competition) : null;

        // "Not on the list" — whatever organizer's row the player was connected to is not them
        $this->releaseOtherParticipants($message->competitionId, $player, keep: null);

        $existingId = $this->findSoftDeletedSelfJoin($message->competitionId, $message->playerId);

        if ($existingId !== null) {
            $participant = $this->participantRepository->get($existingId);
            $participant->restore();
            $participant->connect($player, $this->clock->now());

            if ($registration === null) {
                // Left the waitlist while the event managed registration - it no longer does, so they are going
                $participant->leaveWaitlistOfUnmanagedEvent();
            }
        } else {
            $participant = new CompetitionParticipant(
                id: Uuid::uuid7(),
                name: $player->name ?? $player->code,
                country: $player->country,
                competition: $competition,
                source: ParticipantSource::SelfJoined,
            );

            $participant->connect($player, $this->clock->now());

            $this->participantRepository->save($participant);
        }

        if ($registration !== null) {
            // A registration made again after cancelling starts fresh - at the end of the waitlist, not paid
            $participant->register($registration['status'], $this->clock->now());

            $this->registrationMailer->send(
                $participant,
                $registration['status'] === RegistrationStatus::Waitlisted ? RegistrationEmail::Waitlisted : RegistrationEmail::Reserved,
                $registration['waitlistPosition'],
            );
        }
    }

    /**
     * @return array{status: RegistrationStatus, waitlistPosition: null|int}
     *
     * @throws RegistrationNotOpen
     */
    private function newRegistrationStatus(Competition $competition): array
    {
        $availability = $competition->registrationAvailability(
            $this->clock->now(),
            $this->isCompetitionPubliclyVisible->check($competition->id->toString()),
        );

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

    /**
     * A self-joined row is the player's own and goes away; an organizer's row stays on their list.
     */
    private function releaseOtherParticipants(string $competitionId, Player $player, null|CompetitionParticipant $keep): void
    {
        $connections = $this->getCompetitionParticipants->getPlayerConnections($competitionId, $player->id->toString());

        foreach ($connections as $participantId) {
            if ($keep !== null && $keep->id->toString() === $participantId) {
                continue;
            }

            $participant = $this->participantRepository->get($participantId);

            // A row holding official results is the organiser's record - the player only lets go of it
            if ($participant->source === ParticipantSource::SelfJoined && $this->officialResultsGuard->participantHasOfficialData($participantId) === false) {
                $participant->softDelete($this->clock->now());
            } else {
                $participant->disconnect();
            }
        }
    }

    private function findSoftDeletedSelfJoin(string $competitionId, string $playerId): null|string
    {
        $query = <<<SQL
SELECT id FROM competition_participant
WHERE competition_id = :competitionId
AND player_id = :playerId
AND deleted_at IS NOT NULL
AND source = :source
LIMIT 1
SQL;

        /** @var false|string $result */
        $result = $this->database->executeQuery($query, [
            'competitionId' => $competitionId,
            'playerId' => $playerId,
            'source' => ParticipantSource::SelfJoined->value,
        ])->fetchOne();

        return $result !== false ? $result : null;
    }
}
