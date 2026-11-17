<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\ParticipantLinkRequest;
use SpeedPuzzling\Web\Value\LinkRequestStatus;

/**
 * Persist only (the handlers' transaction flushes). Read under the event's lock by ParticipantLinks.
 */
readonly final class ParticipantLinkRequestRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ParticipantLinkRequest $request): void
    {
        $this->entityManager->persist($request);
    }

    public function find(string $requestId): null|ParticipantLinkRequest
    {
        if (!Uuid::isValid($requestId)) {
            return null;
        }

        return $this->entityManager->find(ParticipantLinkRequest::class, $requestId);
    }

    /**
     * @return list<ParticipantLinkRequest>
     */
    public function pendingOfParticipant(CompetitionParticipant $participant): array
    {
        /** @var list<ParticipantLinkRequest> $requests */
        $requests = $this->entityManager->createQueryBuilder()
            ->select('request')
            ->from(ParticipantLinkRequest::class, 'request')
            ->where('request.participant = :participant')
            ->andWhere('request.status = :pending')
            ->setParameter('participant', $participant->id)
            ->setParameter('pending', LinkRequestStatus::Pending)
            ->orderBy('request.createdAt')
            ->getQuery()
            ->getResult();

        // A request superseded earlier in this unit of work is still pending in the database
        return array_values(array_filter($requests, static fn (ParticipantLinkRequest $request): bool => $request->isPending()));
    }

    /**
     * The player's pending requests at the event - one at most, kept so by ParticipantLinks::request().
     *
     * @return list<ParticipantLinkRequest>
     */
    public function pendingOfPlayer(string $competitionId, string $playerId): array
    {
        /** @var list<ParticipantLinkRequest> $requests */
        $requests = $this->entityManager->createQueryBuilder()
            ->select('request')
            ->from(ParticipantLinkRequest::class, 'request')
            ->where('request.competition = :competition')
            ->andWhere('request.player = :player')
            ->andWhere('request.status = :pending')
            ->setParameter('competition', $competitionId)
            ->setParameter('player', $playerId)
            ->setParameter('pending', LinkRequestStatus::Pending)
            ->orderBy('request.createdAt')
            ->getQuery()
            ->getResult();

        return array_values(array_filter($requests, static fn (ParticipantLinkRequest $request): bool => $request->isPending()));
    }

    /**
     * Every pending request of the event (ParticipantLinks::inBatch()) - one statement.
     *
     * @return list<ParticipantLinkRequest>
     */
    public function pendingOfCompetition(string $competitionId): array
    {
        /** @var list<ParticipantLinkRequest> $requests */
        $requests = $this->entityManager->createQueryBuilder()
            ->select('request')
            ->from(ParticipantLinkRequest::class, 'request')
            ->where('request.competition = :competition')
            ->andWhere('request.status = :pending')
            ->setParameter('competition', $competitionId)
            ->setParameter('pending', LinkRequestStatus::Pending)
            ->orderBy('request.createdAt')
            ->getQuery()
            ->getResult();

        return array_values(array_filter($requests, static fn (ParticipantLinkRequest $request): bool => $request->isPending()));
    }

    /**
     * @param list<string> $requestIds
     * @return array<string, ParticipantLinkRequest> lower-cased id => request
     */
    public function byIds(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        /** @var list<ParticipantLinkRequest> $found */
        $found = $this->entityManager->createQueryBuilder()
            ->select('request')
            ->from(ParticipantLinkRequest::class, 'request')
            ->where('request.id IN (:ids)')
            ->setParameter('ids', $requestIds)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($found as $request) {
            $byId[$request->id->toString()] = $request;
        }

        return $byId;
    }
}
