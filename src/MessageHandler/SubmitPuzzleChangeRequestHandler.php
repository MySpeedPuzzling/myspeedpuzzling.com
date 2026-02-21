<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SubmitPuzzleChangeRequestHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private ManufacturerRepository $manufacturerRepository,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private ImageOptimizer $imageOptimizer,
    ) {
    }

    public function __invoke(SubmitPuzzleChangeRequest $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $reporter = $this->playerRepository->get($message->reporterId);
        $now = $this->clock->now();

        $proposedManufacturer = null;
        if ($message->proposedManufacturerId !== null && Uuid::isValid($message->proposedManufacturerId)) {
            $proposedManufacturer = $this->manufacturerRepository->get($message->proposedManufacturerId);
        }

        // Store proposed image with temporary name - proper SEO name is assigned on approval
        $proposedImagePath = null;
        if ($message->proposedPhoto !== null) {
            $extension = $message->proposedPhoto->guessExtension() ?? 'jpg';
            $proposedImagePath = "proposal-{$message->changeRequestId}.{$extension}";

            $this->imageOptimizer->optimize($message->proposedPhoto->getPathname());

            $stream = fopen($message->proposedPhoto->getPathname(), 'rb');
            $this->filesystem->writeStream($proposedImagePath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $changeRequest = new PuzzleChangeRequest(
            id: Uuid::fromString($message->changeRequestId),
            puzzle: $puzzle,
            reporter: $reporter,
            submittedAt: $now,
            proposedName: $message->proposedName,
            proposedManufacturer: $proposedManufacturer,
            proposedPiecesCount: $message->proposedPiecesCount,
            proposedEan: $message->proposedEan,
            proposedIdentificationNumber: $message->proposedIdentificationNumber,
            proposedImage: $proposedImagePath,
            originalName: $puzzle->name,
            originalManufacturerId: $puzzle->manufacturer?->id,
            originalPiecesCount: $puzzle->piecesCount,
            originalEan: $puzzle->ean,
            originalIdentificationNumber: $puzzle->identificationNumber,
            originalImage: $puzzle->image,
        );

        $this->entityManager->persist($changeRequest);
    }
}
