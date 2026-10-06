<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\Tag;

readonly final class TagRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Tag $tag): void
    {
        $this->entityManager->persist($tag);
    }

    public function nameExists(string $name): bool
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Tag::class, 't')
            ->where('LOWER(t.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * How many competitions and series other than this competition carry the tag - their "competition puzzles"
     * change with it.
     */
    public function countHoldersOtherThan(Tag $tag, Competition $competition): int
    {
        $competitions = $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Competition::class, 'c')
            ->where('c.tag = :tag')
            ->andWhere('c <> :competition')
            ->setParameter('tag', $tag)
            ->setParameter('competition', $competition)
            ->getQuery()
            ->getSingleScalarResult();

        $series = $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(CompetitionSeries::class, 's')
            ->where('s.tag = :tag')
            ->setParameter('tag', $tag)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $competitions + (int) $series;
    }
}
