<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleChangeRequestApproval;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestAlreadyReviewed;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Services\PuzzleImageNamer;
use SpeedPuzzling\Web\Value\PuzzleChangeRequestImageChoice;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\NotificationType;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use SpeedPuzzling\Web\Value\ReviewedPuzzleValues;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Approves a puzzle change request. The admin review sends the whole puzzle as the reviewer wants it
 * (proposed fields or not, image kept, proposed or uploaded); the internal API names the proposed fields
 * to apply as proposed. Both end up as ReviewedPuzzleValues, applied the same way.
 *
 * Everything is validated before the first change: a handler that throws after
 * mutating still has its changes flushed by a later flush in the same request.
 */
#[AsMessageHandler]
readonly final class ApprovePuzzleChangeRequestHandler
{
    public function __construct(
        private PuzzleChangeRequestRepository $puzzleChangeRequestRepository,
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private ManufacturerRepository $manufacturerRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private Filesystem $filesystem,
        private PuzzleImageNamer $puzzleImageNamer,
        private ImageOptimizer $imageOptimizer,
        private PuzzleModerationDecisionRecorder $puzzleModerationDecisionRecorder,
    ) {
    }

    /**
     * @throws PuzzleChangeRequestNotFound
     * @throws PuzzleChangeRequestAlreadyReviewed
     * @throws PuzzleNotFound
     * @throws PlayerNotFound
     * @throws ManufacturerNotFound
     * @throws InvalidPuzzleChangeRequestApproval
     */
    public function __invoke(ApprovePuzzleChangeRequest $message): void
    {
        $changeRequest = $this->puzzleChangeRequestRepository->get($message->changeRequestId);

        if ($changeRequest->status !== PuzzleReportStatus::Pending) {
            throw new PuzzleChangeRequestAlreadyReviewed();
        }

        $puzzle = $this->puzzleRepository->get($changeRequest->puzzle->id->toString());
        $reviewer = $this->playerRepository->get($message->reviewerId);

        $values = $message->reviewed ?? self::proposedValues($changeRequest, $puzzle, $message->selectedFields);

        $name = trim($values->name);

        if ($name === '' || $values->piecesCount <= 0) {
            throw new InvalidPuzzleChangeRequestApproval('Name and pieces count are required.');
        }

        $manufacturer = $values->manufacturerId !== null
            ? $this->manufacturerRepository->get($values->manufacturerId)
            : null;

        if ($values->image === PuzzleChangeRequestImageChoice::Proposed && $changeRequest->proposedImage === null) {
            throw new InvalidPuzzleChangeRequestApproval('No image was proposed.');
        }

        if ($values->image === PuzzleChangeRequestImageChoice::Upload && $values->uploadedImage === null) {
            throw new InvalidPuzzleChangeRequestApproval('Choose the image to upload.');
        }

        // --- validated, now apply ---

        $before = self::snapshot($puzzle);

        $puzzle->name = $name;
        $puzzle->alternativeName = self::nullIfBlank($values->alternativeName);
        $puzzle->manufacturer = $manufacturer;
        $puzzle->piecesCount = $values->piecesCount;
        $puzzle->updateProductIdentifiers(
            ean: self::nullIfBlank($values->ean),
            identificationNumber: self::nullIfBlank($values->identificationNumber),
        );

        // After the fields above: the image's file name is built from the final brand, name and pieces
        if ($values->image === PuzzleChangeRequestImageChoice::Proposed) {
            $this->useProposedImage($changeRequest->proposedImage, $changeRequest->proposedImageRatio, $puzzle);
        }

        if ($values->image === PuzzleChangeRequestImageChoice::Upload) {
            $this->storeUploadedImage($values->uploadedImage, $puzzle);
        }

        $changeRequest->approve($reviewer, $this->clock->now());

        $details = [
            'image' => $values->image->value,
            'before' => $before,
            'after' => self::snapshot($puzzle),
        ];

        if ($message->reviewed === null) {
            $details = ['selectedFields' => $message->selectedFields] + $details;
        }

        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::ChangeRequestApproved,
            decidedBy: $reviewer,
            source: $message->decisionSource,
            puzzleId: $puzzle->id,
            puzzleName: $puzzle->name,
            changeRequestId: $changeRequest->id,
            note: $message->decisionNote,
            details: $details,
        );

        $notification = new Notification(
            id: Uuid::uuid7(),
            player: $changeRequest->reporter,
            type: NotificationType::PuzzleChangeRequestApproved,
            notifiedAt: $this->clock->now(),
            targetChangeRequest: $changeRequest,
        );
        $this->entityManager->persist($notification);
    }

    /**
     * The internal API's selectedFields: those fields as proposed, everything else as the puzzle has it now.
     *
     * @param list<string> $selectedFields
     */
    private static function proposedValues(
        PuzzleChangeRequest $changeRequest,
        Puzzle $puzzle,
        array $selectedFields,
    ): ReviewedPuzzleValues {
        $selected = static fn (string $field): bool => in_array($field, $selectedFields, true);

        $manufacturer = $selected('manufacturer')
            ? ($changeRequest->proposedManufacturer ?? $puzzle->manufacturer)
            : $puzzle->manufacturer;

        return new ReviewedPuzzleValues(
            name: $selected('name') ? ($changeRequest->proposedName ?? $puzzle->name) : $puzzle->name,
            alternativeName: $puzzle->alternativeName,
            manufacturerId: $manufacturer?->id->toString(),
            piecesCount: $selected('piecesCount') ? ($changeRequest->proposedPiecesCount ?? $puzzle->piecesCount) : $puzzle->piecesCount,
            ean: $selected('ean') ? ($changeRequest->proposedEan ?? $puzzle->ean) : $puzzle->ean,
            identificationNumber: $selected('identificationNumber')
                ? ($changeRequest->proposedIdentificationNumber ?? $puzzle->identificationNumber)
                : $puzzle->identificationNumber,
            image: $selected('image') && $changeRequest->proposedImage !== null
                ? PuzzleChangeRequestImageChoice::Proposed
                : PuzzleChangeRequestImageChoice::Keep,
        );
    }

    private function useProposedImage(string $proposedImage, null|float $proposedImageRatio, Puzzle $puzzle): void
    {
        $newImagePath = $this->newImagePath($puzzle, pathinfo($proposedImage, PATHINFO_EXTENSION) ?: 'jpg');

        $this->filesystem->copy($proposedImage, $newImagePath);
        $this->filesystem->delete($proposedImage);

        $puzzle->image = $newImagePath;
        $puzzle->imageRatio = $proposedImageRatio;
    }

    private function storeUploadedImage(UploadedFile $uploadedImage, Puzzle $puzzle): void
    {
        $newImagePath = $this->newImagePath($puzzle, $uploadedImage->guessExtension() ?? 'jpg');

        $this->imageOptimizer->optimize($uploadedImage->getPathname());
        $imageRatio = $this->imageOptimizer->getImageRatio($uploadedImage->getPathname());

        // Stream is better because it is memory safe
        $stream = fopen($uploadedImage->getPathname(), 'rb');
        $this->filesystem->writeStream($newImagePath, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        $puzzle->image = $newImagePath;
        $puzzle->imageRatio = $imageRatio;
    }

    private function newImagePath(Puzzle $puzzle, string $extension): string
    {
        $newImagePath = $this->puzzleImageNamer->generateFilename(
            $puzzle->manufacturer !== null ? $puzzle->manufacturer->name : 'puzzle',
            $puzzle->name,
            $puzzle->piecesCount,
            $puzzle->id->toString(),
            $extension,
        );

        // If generated name matches current puzzle image, force unique name for browser cache busting
        if ($newImagePath === $puzzle->image) {
            $uuid = substr(Uuid::uuid7()->toString(), 0, 8);
            $pathInfo = pathinfo($newImagePath);
            $newImagePath = $pathInfo['filename'] . "-$uuid." . ($pathInfo['extension'] ?? 'jpg');
        }

        return $newImagePath;
    }

    /**
     * @return array<string, null|string|int>
     */
    private static function snapshot(Puzzle $puzzle): array
    {
        return [
            'name' => $puzzle->name,
            'alternativeName' => $puzzle->alternativeName,
            'manufacturerId' => $puzzle->manufacturer?->id->toString(),
            'manufacturerName' => $puzzle->manufacturer?->name,
            'piecesCount' => $puzzle->piecesCount,
            'ean' => $puzzle->ean,
            'identificationNumber' => $puzzle->identificationNumber,
            'image' => $puzzle->image,
        ];
    }

    private static function nullIfBlank(null|string $value): null|string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
