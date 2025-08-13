<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Liip\ImagineBundle\Message\WarmupCache;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class AddPuzzleHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private ManufacturerRepository $manufacturerRepository,
        private Filesystem $filesystem,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ManufacturerNotFound
     */
    public function __invoke(AddPuzzle $message): void
    {
        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->userId);
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
            );

            $this->entityManager->persist($manufacturer);
        }

        $puzzlePhotoPath = null;
        if ($message->puzzlePhoto !== null) {
            $extension = $message->puzzlePhoto->guessExtension();
            $timestamp = $this->clock->now()->getTimestamp();
            $puzzlePhotoPath = "$message->puzzleId-$timestamp.$extension";

            // Stream is better because it is memory safe
            $stream = fopen($message->puzzlePhoto->getPathname(), 'rb');
            $this->filesystem->writeStream($puzzlePhotoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $this->messageBus->dispatch(
                new WarmupCache($puzzlePhotoPath),
            );
        }

        $puzzle = new Puzzle(
            $message->puzzleId,
            $message->piecesCount,
            $message->puzzleName,
            approved: false,
            image: $puzzlePhotoPath,
            manufacturer: $manufacturer,
            addedByUser: $player,
            addedAt: $now,
            identificationNumber: $message->puzzleIdentificationNumber,
            ean: $message->puzzleEan,
        );

        $this->entityManager->persist($puzzle);
    }
}
