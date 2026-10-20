<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ModeratorInvitation;
use SpeedPuzzling\Web\Exceptions\ModeratorInvitationNotFound;

readonly final class ModeratorInvitationRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ModeratorInvitation $invitation): void
    {
        $this->entityManager->persist($invitation);
    }

    /**
     * @throws ModeratorInvitationNotFound
     */
    public function get(string $invitationId): ModeratorInvitation
    {
        $invitation = Uuid::isValid($invitationId) ? $this->entityManager->find(ModeratorInvitation::class, $invitationId) : null;

        if ($invitation === null) {
            throw new ModeratorInvitationNotFound();
        }

        return $invitation;
    }

    /**
     * The one open invitation of a player - InviteModeratorHandler never sends a second one while one is open
     */
    public function findPendingForPlayer(string $playerId): null|ModeratorInvitation
    {
        if (Uuid::isValid($playerId) === false) {
            return null;
        }

        /** @var null|ModeratorInvitation $invitation */
        $invitation = $this->entityManager->createQueryBuilder()
            ->select('invitation')
            ->from(ModeratorInvitation::class, 'invitation')
            ->where('invitation.player = :playerId')
            ->andWhere('invitation.answeredAt IS NULL')
            ->andWhere('invitation.withdrawnAt IS NULL')
            ->setParameter('playerId', $playerId)
            ->orderBy('invitation.invitedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $invitation;
    }
}
