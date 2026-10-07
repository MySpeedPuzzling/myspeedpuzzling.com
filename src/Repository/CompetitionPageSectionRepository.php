<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionPageSection;
use SpeedPuzzling\Web\Exceptions\PageSectionNotFound;
use SpeedPuzzling\Web\Value\PageSectionOwner;

readonly final class CompetitionPageSectionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws PageSectionNotFound
     */
    public function get(string $sectionId): CompetitionPageSection
    {
        if (!Uuid::isValid($sectionId)) {
            throw new PageSectionNotFound();
        }

        $section = $this->entityManager->find(CompetitionPageSection::class, $sectionId);

        return $section ?? throw new PageSectionNotFound();
    }

    /**
     * The owner's own sections in page order (a series' sections are not an edition's own).
     *
     * @return list<CompetitionPageSection>
     */
    public function allOf(PageSectionOwner $owner): array
    {
        /** @var list<CompetitionPageSection> $sections */
        $sections = $this->entityManager->createQueryBuilder()
            ->select('section')
            ->from(CompetitionPageSection::class, 'section')
            ->where($owner->isSeries() ? 'section.series = :ownerId' : 'section.competition = :ownerId')
            ->setParameter('ownerId', $owner->id())
            ->orderBy('section.position', 'ASC')
            ->addOrderBy('section.createdAt', 'ASC')
            ->addOrderBy('section.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $sections;
    }

    public function save(CompetitionPageSection $section): void
    {
        $this->entityManager->persist($section);
    }

    public function delete(CompetitionPageSection $section): void
    {
        $this->entityManager->remove($section);
    }
}
