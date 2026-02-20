<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Liip\ImagineBundle\Message\WarmupCache;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\PuzzleImageNamer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class SubmitPuzzleChangeRequestHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private ManufacturerRepository $manufacturerRepository,
        private Filesystem $filesystem,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private PuzzleImageNamer $puzzleImageNamer,
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

        // Handle image upload with SEO-friendly naming
        $proposedImagePath = null;
        if ($message->proposedPhoto !== null) {
            $extension = $message->proposedPhoto->guessExtension() ?? 'jpg';
            $brandName = $proposedManufacturer !== null
                ? $proposedManufacturer->name
                : ($puzzle->manufacturer !== null ? $puzzle->manufacturer->name : 'puzzle');
            $proposedImagePath = $this->puzzleImageNamer->generateFilename(
                $brandName,
                $message->proposedName,
                $message->proposedPiecesCount,
                $extension,
            );

            $this->imageOptimizer->optimize($message->proposedPhoto->getPathname());

            $stream = fopen($message->proposedPhoto->getPathname(), 'rb');
            $this->filesystem->writeStream($proposedImagePath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $this->messageBus->dispatch(new WarmupCache($proposedImagePath));
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
