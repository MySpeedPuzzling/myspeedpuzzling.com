<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Value\RegistrationStatus;

readonly final class CompetitionParticipantRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CompetitionParticipantNotFound
     */
    public function get(string $participantId): CompetitionParticipant
    {
        if (!Uuid::isValid($participantId)) {
            throw new CompetitionParticipantNotFound();
        }

        $participant = $this->entityManager->find(CompetitionParticipant::class, $participantId);

        return $participant ?? throw new CompetitionParticipantNotFound();
    }

    /**
     * A participant of the given event, removed ones included - an organiser's action on another event's participant is a
     * 404 (the organiser was authorised for $competitionId only).
     *
     * @throws CompetitionParticipantNotFound
     */
    public function getOfCompetition(string $competitionId, string $participantId): CompetitionParticipant
    {
        $participant = $this->get($participantId);

        if ($participant->competition->id->toString() !== strtolower($competitionId)) {
            throw new CompetitionParticipantNotFound();
        }

        return $participant;
    }

    /**
     * A participant of the given event that is not deleted - an organiser's action on another event's participant, or
     * on a removed one, is a 404 (the organiser was authorised for $competitionId only).
     *
     * @throws CompetitionParticipantNotFound
     */
    public function getActiveOfCompetition(string $competitionId, string $participantId): CompetitionParticipant
    {
        $participant = $this->get($participantId);

        if ($participant->competition->id->toString() !== strtolower($competitionId) || $participant->isDeleted()) {
            throw new CompetitionParticipantNotFound();
        }

        return $participant;
    }

    /**
     * The event's waitlist, first in line first. With $includeDeleted also the rows of people who left the waitlist -
     * they keep the status on the removed row, and a removed row can come back (joining again, the organiser's restore).
     *
     * @return list<CompetitionParticipant>
     */
    public function waitlistOf(string $competitionId, bool $includeDeleted = false): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('participant')
            ->from(CompetitionParticipant::class, 'participant')
            ->where('participant.competition = :competitionId')
            ->andWhere('participant.registrationStatus = :waitlisted')
            ->setParameter('competitionId', $competitionId)
            ->setParameter('waitlisted', RegistrationStatus::Waitlisted)
            ->orderBy('participant.registeredAt', 'ASC')
            ->addOrderBy('participant.id', 'ASC');

        if ($includeDeleted === false) {
            $queryBuilder->andWhere('participant.deletedAt IS NULL');
        }

        /** @var list<CompetitionParticipant> $participants */
        $participants = $queryBuilder->getQuery()->getResult();

        return $participants;
    }

    public function save(CompetitionParticipant $participant): void
    {
        $this->entityManager->persist($participant);
    }

    public function delete(CompetitionParticipant $participant): void
    {
        $this->entityManager->remove($participant);
    }
}
