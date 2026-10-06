<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Stopwatch;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\StartStopwatch;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class StartStopwatchHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private SecretPuzzleAccess $secretPuzzleAccess,
    ) {
    }

    /**
     * @throws PuzzleNotFound
     */
    public function __invoke(StartStopwatch $message): void
    {
        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->userId);
        $puzzle = $message->puzzleId !== null ? $this->puzzleRepository->get($message->puzzleId) : null;

        // A puzzle a competition keeps secret is nobody's to time but its organisers' (SecretPuzzleAccess) - a stopwatch
        // shows its name
        if ($puzzle !== null) {
            $this->secretPuzzleAccess->assertPuzzleUsableBy($puzzle, $player->id->toString());
        }

        $stopwatch = new Stopwatch(
            $message->stopwatchId,
            $player,
            $puzzle,
        );

        $stopwatch->start(new \DateTimeImmutable());

        $this->entityManager->persist($stopwatch);
    }
}
