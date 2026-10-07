<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzlingTeamMember;
use SpeedPuzzling\Web\Entity\SuspiciousTimeCase;
use SpeedPuzzling\Web\Entity\SuspiciousTimeNotice;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeNoticeNotFound;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;

readonly final class SuspiciousTimeNoticeRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(SuspiciousTimeNotice $notice): void
    {
        $this->entityManager->persist($notice);
    }

    /**
     * @throws SuspiciousTimeNoticeNotFound
     */
    public function get(string $noticeId): SuspiciousTimeNotice
    {
        if (!Uuid::isValid($noticeId)) {
            throw new SuspiciousTimeNoticeNotFound();
        }

        return $this->entityManager->find(SuspiciousTimeNotice::class, $noticeId) ?? throw new SuspiciousTimeNoticeNotFound();
    }

    /**
     * This person's notice of the mark in force - what their reply on the review page is about. Nobody else may
     * answer: a case without such a notice is not found, nor one whose result the person is no longer in (neither its
     * tracker nor a registered member of its pair/team - GetPlayerSuspiciousTimes::sqlStillInTime()).
     *
     * @throws SuspiciousTimeNoticeNotFound
     */
    public function getCurrentOf(string $caseId, string $playerId): SuspiciousTimeNotice
    {
        if (!Uuid::isValid($caseId) || !Uuid::isValid($playerId)) {
            throw new SuspiciousTimeNoticeNotFound();
        }

        /** @var null|SuspiciousTimeNotice $notice */
        $notice = $this->entityManager->createQueryBuilder()
            ->select('n', 'c')
            ->from(SuspiciousTimeNotice::class, 'n')
            ->innerJoin('n.case', 'c')
            ->innerJoin('c.time', 't')
            ->where('c.id = :caseId')
            ->andWhere('IDENTITY(n.player) = :playerId')
            ->andWhere('c.status = :marked')
            ->andWhere('n.markedAt = c.markedAt')
            ->andWhere('IDENTITY(t.player) = :playerId OR EXISTS (SELECT 1 FROM ' . PuzzlingTeamMember::class . ' m WHERE m.team = t.puzzlingTeam AND IDENTITY(m.player) = :playerId)')
            ->setParameter('caseId', $caseId)
            ->setParameter('playerId', $playerId)
            ->setParameter('marked', SuspiciousTimeCaseStatus::Marked)
            ->getQuery()
            ->getOneOrNullResult();

        return $notice ?? throw new SuspiciousTimeNoticeNotFound();
    }

    /**
     * Every notice of the case, of every mark and person.
     *
     * @return list<SuspiciousTimeNotice>
     */
    public function findOfCase(SuspiciousTimeCase $case): array
    {
        /** @var list<SuspiciousTimeNotice> $notices */
        $notices = $this->entityManager->getRepository(SuspiciousTimeNotice::class)->findBy(['case' => $case]);

        return $notices;
    }
}
