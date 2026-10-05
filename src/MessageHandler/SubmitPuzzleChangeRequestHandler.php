<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\ManufacturerResolver;
use SpeedPuzzling\Web\Value\LanguageTag;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SubmitPuzzleChangeRequestHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private ManufacturerResolver $manufacturerResolver,
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

        // A typed name no brand matches is a new, unapproved brand right away, like on the add form - it is how a
        // misspelled brand gets its right name. The review approves it, or deletes it when it stays unused
        // (ChangeRequestCreatedBrandSettler)
        $proposedManufacturer = null;
        $createdManufacturerName = null;
        $proposedBrand = trim($message->proposedBrand ?? '');

        if ($proposedBrand !== '') {
            $proposedManufacturer = $this->manufacturerResolver->findExisting($proposedBrand);

            if ($proposedManufacturer === null) {
                $proposedManufacturer = $this->manufacturerResolver->create($proposedBrand, $reporter, $now);
                $createdManufacturerName = $proposedManufacturer->name;
            }
        }

        // Store proposed image with temporary name - proper SEO name is assigned on approval
        $proposedImagePath = null;
        $proposedImageRatio = null;
        if ($message->proposedPhoto !== null) {
            $extension = $message->proposedPhoto->guessExtension() ?? 'jpg';
            $proposedImagePath = "proposal-{$message->changeRequestId}.{$extension}";

            $this->imageOptimizer->optimize($message->proposedPhoto->getPathname());
            $proposedImageRatio = $this->imageOptimizer->getImageRatio($message->proposedPhoto->getPathname());

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
            proposedEan: self::proposedCodes($message->proposedEans->toStored(), $puzzle->eans()->toStored()),
            proposedIdentificationNumber: self::proposedCodes($message->proposedBrandCodes->toStored(), $puzzle->brandCodes()->toStored()),
            proposedImage: $proposedImagePath,
            proposedImageRatio: $proposedImageRatio,
            // Stored as the puzzle would keep them next to the proposed main title (PuzzleNames::cleanedFor())
            proposedAlternativeNames: $message->proposedAlternativeNames?->cleanedFor($message->proposedName)->toArray(),
            proposedNameLanguage: $message->proposedAlternativeNames !== null
                ? LanguageTag::ofMainTitle($message->proposedNameLanguage)
                : null,
            originalName: $puzzle->name,
            originalManufacturerId: $puzzle->manufacturer?->id,
            originalPiecesCount: $puzzle->piecesCount,
            originalEan: $puzzle->ean,
            originalIdentificationNumber: $puzzle->identificationNumber,
            originalImage: $puzzle->image,
            originalAlternativeNames: $message->originalAlternativeNames->toArray(),
            originalNameLanguage: $message->originalNameLanguage,
            createdManufacturerName: $createdManufacturerName,
        );

        $this->entityManager->persist($changeRequest);
    }

    /**
     * A code list as the change request stores it (PuzzleChangeRequest::$proposedEan): null when the puzzle has these
     * codes already, '' when every code goes, otherwise the canonical list.
     */
    private static function proposedCodes(null|string $proposed, null|string $current): null|string
    {
        return $proposed === $current ? null : ($proposed ?? '');
    }
}
