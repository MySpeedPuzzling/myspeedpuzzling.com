<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleIdTaken;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Query\IsPuzzleInUse;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\ManufacturerResolver;
use SpeedPuzzling\Web\Services\PuzzleImageNamer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPuzzleHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private ManufacturerResolver $manufacturerResolver,
        private Filesystem $filesystem,
        private ImageOptimizer $imageOptimizer,
        private PuzzleImageNamer $puzzleImageNamer,
        private PuzzleRepository $puzzleRepository,
        private IsPuzzleInUse $isPuzzleInUse,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ManufacturerNotFound
     * @throws PuzzleIdTaken
     */
    public function __invoke(AddPuzzle $message): void
    {
        // First: a second request with the same id waits here until this one commits, then finds the puzzle below
        $this->idLock->lockUntilCommit($message->puzzleId);

        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->userId);

        // The add form sends the new puzzle's id along: a puzzle with it means the same form arrived again and
        // the puzzle is already there (docs/features/duplicate-results.md, Layer 1)
        $existingPuzzle = $this->puzzleRepository->findById($message->puzzleId);

        if ($existingPuzzle !== null) {
            if ($existingPuzzle->addedByUser?->id->equals($player->id) !== true) {
                throw new PuzzleIdTaken();
            }

            // The result saved with it was refused (e.g. a mistyped piece count made the time impossible), so the
            // form came back with what the player corrected. Once anything uses the puzzle it stays as it is
            if ($existingPuzzle->approved === false && $this->isPuzzleInUse->check($existingPuzzle->id->toString()) === false) {
                $this->correct($existingPuzzle, $message, $player);
            }

            return;
        }

        $now = $this->clock->now();
        $manufacturer = $this->manufacturerResolver->resolve($message->brand, $player, $now);
        [$puzzlePhotoPath, $puzzleImageRatio] = $this->storePhoto($message, $manufacturer);

        $puzzle = new Puzzle(
            $message->puzzleId,
            $message->piecesCount,
            $message->puzzleName,
            approved: false,
            image: $puzzlePhotoPath,
            imageRatio: $puzzleImageRatio,
            manufacturer: $manufacturer,
            alternativeNames: $message->alternativeNames,
            addedByUser: $player,
            addedAt: $now,
            brandCodes: $message->brandCodes,
            eans: $message->eans,
        );

        $this->entityManager->persist($puzzle);
    }

    /**
     * @throws ManufacturerNotFound
     */
    private function correct(Puzzle $puzzle, AddPuzzle $message, Player $player): void
    {
        $now = $this->clock->now();

        // The brand typed again finds the one this form created the first time - not a second new brand
        $manufacturer = $this->manufacturerResolver->resolve($message->brand, $player, $now);

        [$puzzlePhotoPath, $puzzleImageRatio] = $this->storePhoto($message, $manufacturer);

        $puzzle->correctNewlyAdded(
            name: $message->puzzleName,
            alternativeNames: $message->alternativeNames,
            piecesCount: $message->piecesCount,
            manufacturer: $manufacturer,
            image: $puzzlePhotoPath,
            imageRatio: $puzzleImageRatio,
            eans: $message->eans,
            brandCodes: $message->brandCodes,
            now: $now,
        );
    }

    /**
     * @return array{string, float}
     */
    private function storePhoto(AddPuzzle $message, Manufacturer $manufacturer): array
    {
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

        return [$puzzlePhotoPath, $puzzleImageRatio];
    }
}
