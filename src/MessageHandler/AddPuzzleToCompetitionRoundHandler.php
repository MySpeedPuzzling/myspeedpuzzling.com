<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPuzzleToCompetitionRoundHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompetitionRoundRepository $competitionRoundRepository,
        private CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private ManufacturerRepository $manufacturerRepository,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private ImageOptimizer $imageOptimizer,
    ) {
    }

    public function __invoke(AddPuzzleToCompetitionRound $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        if (Uuid::isValid($message->puzzle)) {
            // Existing puzzle
            $puzzle = $this->puzzleRepository->get($message->puzzle);
        } else {
            // New puzzle
            $puzzle = $this->createNewPuzzle($message);
        }

        if ($message->hideUntilRoundStarts) {
            $puzzle->hideUntil = $round->startsAt;
        }

        $roundPuzzle = new CompetitionRoundPuzzle(
            id: $message->roundPuzzleId,
            round: $round,
            puzzle: $puzzle,
            hideUntilRoundStarts: $message->hideUntilRoundStarts,
        );

        $this->competitionRoundPuzzleRepository->save($roundPuzzle);
    }

    private function createNewPuzzle(AddPuzzleToCompetitionRound $message): Puzzle
    {
        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->userId);
        $now = $this->clock->now();

        if (Uuid::isValid($message->brand)) {
            $manufacturer = $this->manufacturerRepository->get($message->brand);
        } else {
            $manufacturer = new Manufacturer(
                Uuid::uuid7(),
                $message->brand,
                false,
                $player,
                $now,
            );
            $this->entityManager->persist($manufacturer);
        }

        $puzzleId = Uuid::uuid7();
        $puzzlePhotoPath = null;

        if ($message->puzzlePhoto !== null) {
            $extension = $message->puzzlePhoto->guessExtension();
            $timestamp = $now->getTimestamp();
            $puzzlePhotoPath = "$puzzleId-$timestamp.$extension";

            $this->imageOptimizer->optimize($message->puzzlePhoto->getPathname());

            $stream = fopen($message->puzzlePhoto->getPathname(), 'rb');
            $this->filesystem->writeStream($puzzlePhotoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $puzzle = new Puzzle(
            $puzzleId,
            $message->piecesCount ?? 0,
            $message->puzzle,
            approved: false,
            image: $puzzlePhotoPath,
            manufacturer: $manufacturer,
            addedByUser: $player,
            addedAt: $now,
            identificationNumber: $message->puzzleIdentificationNumber,
            ean: $message->puzzleEan !== null ? (ltrim($message->puzzleEan, '0') ?: null) : null,
        );

        $this->entityManager->persist($puzzle);

        return $puzzle;
    }
}
