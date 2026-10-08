<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\EventUrlRedirect;
use SpeedPuzzling\Web\Value\EventUrlPath;

readonly final class EventUrlRedirectRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByPath(EventUrlPath $path): null|EventUrlRedirect
    {
        return $this->entityManager->getRepository(EventUrlRedirect::class)->findOneBy([
            'seriesSlug' => $path->seriesSlug,
            'competitionSlug' => $path->competitionSlug,
            'roundSlug' => $path->roundSlug,
        ]);
    }

    public function save(EventUrlRedirect $redirect): void
    {
        $this->entityManager->persist($redirect);
    }
}
