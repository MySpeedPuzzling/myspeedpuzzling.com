<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\StudioInvite;
use SpeedPuzzling\Web\Exceptions\StudioInviteNotFound;
use SpeedPuzzling\Web\Value\StudioInviteStatus;

readonly final class StudioInviteRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws StudioInviteNotFound
     */
    public function get(string $inviteId): StudioInvite
    {
        if (!Uuid::isValid($inviteId)) {
            throw new StudioInviteNotFound();
        }

        $invite = $this->entityManager->find(StudioInvite::class, $inviteId);

        return $invite ?? throw new StudioInviteNotFound();
    }

    /**
     * For a write: the row is locked until the handler's transaction ends and read again (SELECT … FOR UPDATE), so an
     * answer, the host's end and LiveKit's webhook about the same invitation take turns - none of them overwrites what
     * another one just decided. In a transaction only (every handler has one).
     *
     * @throws StudioInviteNotFound
     */
    public function getForUpdate(string $inviteId): StudioInvite
    {
        if (!Uuid::isValid($inviteId)) {
            throw new StudioInviteNotFound();
        }

        $invite = $this->entityManager->createQueryBuilder()
            ->select('invite')
            ->from(StudioInvite::class, 'invite')
            ->where('invite.id = :id')
            ->setParameter('id', $inviteId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $invite instanceof StudioInvite ? $invite : throw new StudioInviteNotFound();
    }

    /**
     * The participant's latest invitation to the round's studio - null when they were never invited
     */
    public function latestOf(string $roundId, string $participantId): null|StudioInvite
    {
        if (!Uuid::isValid($roundId) || !Uuid::isValid($participantId)) {
            return null;
        }

        $invite = $this->entityManager->createQueryBuilder()
            ->select('invite')
            ->from(StudioInvite::class, 'invite')
            ->where('invite.round = :roundId')
            ->andWhere('invite.participant = :participantId')
            ->setParameter('roundId', $roundId)
            ->setParameter('participantId', $participantId)
            ->orderBy('invite.invitedAt', 'DESC')
            ->addOrderBy('invite.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $invite instanceof StudioInvite ? $invite : null;
    }

    /**
     * The participant's invitation that is on air in the round's studio (`joined`), locked for the write like
     * getForUpdate() - null when there is none
     */
    public function joinedOfForUpdate(string $roundId, string $participantId): null|StudioInvite
    {
        if (!Uuid::isValid($roundId) || !Uuid::isValid($participantId)) {
            return null;
        }

        $invite = $this->entityManager->createQueryBuilder()
            ->select('invite')
            ->from(StudioInvite::class, 'invite')
            ->where('invite.round = :roundId')
            ->andWhere('invite.participant = :participantId')
            ->andWhere('invite.status = :joined')
            ->setParameter('roundId', $roundId)
            ->setParameter('participantId', $participantId)
            ->setParameter('joined', StudioInviteStatus::Joined->value)
            ->orderBy('invite.invitedAt', 'DESC')
            ->addOrderBy('invite.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $invite instanceof StudioInvite ? $invite : null;
    }

    public function save(StudioInvite $invite): void
    {
        $this->entityManager->persist($invite);
    }

    public function delete(StudioInvite $invite): void
    {
        $this->entityManager->remove($invite);
    }
}
