<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ComparisonSubject;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\ComparisonSubjectNotFound;

readonly final class ComparisonSubjectRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Only ever the owner's own row - somebody else's id is as unknown as a made-up one.
     *
     * @throws ComparisonSubjectNotFound
     */
    public function get(Player $owner, string $comparisonSubjectId): ComparisonSubject
    {
        if (Uuid::isValid($comparisonSubjectId) === false) {
            throw new ComparisonSubjectNotFound();
        }

        $subject = $this->entityManager->getRepository(ComparisonSubject::class)->findOneBy([
            'id' => $comparisonSubjectId,
            'player' => $owner,
        ]);

        if ($subject === null) {
            throw new ComparisonSubjectNotFound();
        }

        return $subject;
    }

    /**
     * Every line-up of the owner (all kinds), oldest first. The teams come along in the same query - a row's kind
     * depends on the team's size.
     *
     * @return list<ComparisonSubject>
     */
    public function listByOwner(Player $owner): array
    {
        /** @var list<ComparisonSubject> $subjects */
        $subjects = $this->entityManager->createQueryBuilder()
            ->select('subject', 'team')
            ->from(ComparisonSubject::class, 'subject')
            ->leftJoin('subject.subjectTeam', 'team')
            ->where('subject.player = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('subject.addedAt', 'ASC')
            ->addOrderBy('subject.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $subjects;
    }

    public function save(ComparisonSubject $subject): void
    {
        $this->entityManager->persist($subject);
    }

    public function delete(ComparisonSubject $subject): void
    {
        $this->entityManager->remove($subject);
    }
}
