<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Stopwatch;
use SpeedPuzzling\Web\Exceptions\StopwatchNotFound;

readonly final class StopwatchRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws StopwatchNotFound()
     */
    public function get(string $stopwatchId): Stopwatch
    {
        if (!Uuid::isValid($stopwatchId)) {
            throw new StopwatchNotFound();
        }

        $puzzle = $this->entityManager->find(Stopwatch::class, $stopwatchId);

        return $puzzle ?? throw new StopwatchNotFound();
    }
}
