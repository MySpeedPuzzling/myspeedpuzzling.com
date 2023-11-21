<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPuzzleHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private ManufacturerRepository $manufacturerRepository,
    ) {
    }

    /**
     * @throws ManufacturerNotFound
     */
    public function __invoke(AddPuzzle $message): void
    {
        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->userId);
        $now = new DateTimeImmutable();

        if ($message->manufacturerId !== null && Uuid::isValid($message->manufacturerId)) {
            $manufacturer = $this->manufacturerRepository->get($message->manufacturerId);
        } else {
            assert($message->manufacturerName !== null);

            $manufacturer = new Manufacturer(
                Uuid::uuid7(),
                $message->manufacturerName,
                false,
                $player,
                $now,
            );

            $this->entityManager->persist($manufacturer);
        }

        $puzzle = new Puzzle(
            $message->puzzleId,
            $message->piecesCount,
            $message->puzzleName,
            false,
            manufacturer: $manufacturer,
            addedByUser: $player,
            addedAt: $now,
        );

        $this->entityManager->persist($puzzle);
    }
}
