<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleTracking;
use SpeedPuzzling\Web\Exceptions\PuzzleTrackingNotFound;

readonly final class PuzzleTrackingRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws PuzzleTrackingNotFound
     */
    public function get(string $puzzleTrackingId): PuzzleTracking
    {
        if (!Uuid::isValid($puzzleTrackingId)) {
            throw new PuzzleTrackingNotFound();
        }

        $puzzleTracking = $this->entityManager->find(PuzzleTracking::class, $puzzleTrackingId);

        return $puzzleTracking ?? throw new PuzzleTrackingNotFound();
    }
}
