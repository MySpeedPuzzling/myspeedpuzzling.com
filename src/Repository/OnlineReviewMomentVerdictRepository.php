<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineReviewMomentVerdict;

readonly final class OnlineReviewMomentVerdictRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The verdict on a finish moment (its event id) - null while it is not marked
     */
    public function ofEvent(string $eventId): null|OnlineReviewMomentVerdict
    {
        if (!Uuid::isValid($eventId)) {
            return null;
        }

        $verdict = $this->entityManager->getRepository(OnlineReviewMomentVerdict::class)->findOneBy([
            'event' => strtolower($eventId),
        ]);

        return $verdict instanceof OnlineReviewMomentVerdict ? $verdict : null;
    }

    public function save(OnlineReviewMomentVerdict $verdict): void
    {
        $this->entityManager->persist($verdict);
    }

    public function delete(OnlineReviewMomentVerdict $verdict): void
    {
        $this->entityManager->remove($verdict);
    }
}
