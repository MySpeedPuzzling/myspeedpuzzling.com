<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionPageSection;
use SpeedPuzzling\Web\Exceptions\PageSectionNotFound;

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

    public function save(CompetitionPageSection $section): void
    {
        $this->entityManager->persist($section);
    }

    public function delete(CompetitionPageSection $section): void
    {
        $this->entityManager->remove($section);
    }
}
