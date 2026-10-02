<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleIdTaken;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\GenerateManufacturerSlug;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\PuzzleImageNamer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPuzzleHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private ManufacturerRepository $manufacturerRepository,
        private Filesystem $filesystem,
        private ImageOptimizer $imageOptimizer,
        private GenerateManufacturerSlug $generateManufacturerSlug,
        private PuzzleImageNamer $puzzleImageNamer,
        private PuzzleRepository $puzzleRepository,
    ) {
    }

    /**
     * @throws ManufacturerNotFound
     * @throws PuzzleIdTaken
     */
    public function __invoke(AddPuzzle $message): void
    {
        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->userId);

        // The add form sends the new puzzle's id along: a puzzle with it means the same form arrived again and
        // the puzzle is already there (docs/features/duplicate-results.md, Layer 1)
        $existingPuzzle = $this->puzzleRepository->findById($message->puzzleId);

        if ($existingPuzzle !== null) {
            if ($existingPuzzle->addedByUser?->id->equals($player->id) === true) {
                return;
            }

            throw new PuzzleIdTaken();
        }
        $now = new DateTimeImmutable();

        if (Uuid::isValid($message->brand)) {
            $manufacturer = $this->manufacturerRepository->get($message->brand);
        } else {
            $manufacturer = new Manufacturer(
                Uuid::uuid7(),
                $message->brand,
                false,
                $player,
                $now,
                slug: $this->generateManufacturerSlug->fromName($message->brand),
            );

            $this->entityManager->persist($manufacturer);
        }

        $extension = $message->puzzlePhoto->guessExtension() ?? 'jpg';
        $puzzlePhotoPath = $this->puzzleImageNamer->generateFilename(
            $manufacturer->name,
            $message->puzzleName,
            $message->piecesCount,
            $message->puzzleId->toString(),
            $extension,
        );

        $this->imageOptimizer->optimize($message->puzzlePhoto->getPathname());
        $puzzleImageRatio = $this->imageOptimizer->getImageRatio($message->puzzlePhoto->getPathname());

        // Stream is better because it is memory safe
        $stream = fopen($message->puzzlePhoto->getPathname(), 'rb');
        $this->filesystem->writeStream($puzzlePhotoPath, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        $puzzle = new Puzzle(
            $message->puzzleId,
            $message->piecesCount,
            $message->puzzleName,
            approved: false,
            image: $puzzlePhotoPath,
            imageRatio: $puzzleImageRatio,
            manufacturer: $manufacturer,
            addedByUser: $player,
            addedAt: $now,
            identificationNumber: $message->puzzleIdentificationNumber,
            ean: $message->puzzleEan !== null ? (ltrim($message->puzzleEan, '0') ?: null) : null,
        );

        $this->entityManager->persist($puzzle);
    }
}
