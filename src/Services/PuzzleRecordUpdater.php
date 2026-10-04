<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Value\PuzzleImageChoice;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Saves a puzzle's catalogue record - name, alternative name, brand, pieces, codes and image. The one place
 * that does it for a change request approval (ApprovePuzzleChangeRequestHandler) and a moderator's direct
 * edit (EditPuzzleHandler).
 *
 * Every value is checked before the first change: a handler that throws after mutating still has its
 * changes flushed by a later flush in the same request. The caller records the returned before/after
 * snapshots in the decision log - they are what the puzzle's history shows.
 */
readonly final class PuzzleRecordUpdater
{
    public function __construct(
        private ManufacturerRepository $manufacturerRepository,
        private Filesystem $filesystem,
        private PuzzleImageNamer $puzzleImageNamer,
        private ImageOptimizer $imageOptimizer,
    ) {
    }

    /**
     * @param null|string $proposedImage A change request's proposed image - what PuzzleImageChoice::Proposed uses
     *
     * @return array{before: array<string, null|string|int>, after: array<string, null|string|int>}
     *
     * @throws InvalidPuzzleValues
     * @throws ManufacturerNotFound
     */
    public function update(
        Puzzle $puzzle,
        PuzzleRecordValues $values,
        null|string $proposedImage = null,
        null|float $proposedImageRatio = null,
    ): array {
        $name = trim($values->name);

        if ($name === '' || $values->piecesCount <= 0) {
            throw new InvalidPuzzleValues('Name and pieces count are required.');
        }

        $manufacturer = $values->manufacturerId !== null
            ? $this->manufacturerRepository->get($values->manufacturerId)
            : null;

        if ($values->image === PuzzleImageChoice::Proposed && $proposedImage === null) {
            throw new InvalidPuzzleValues('No image was proposed.');
        }

        if ($values->image === PuzzleImageChoice::Upload && $values->uploadedImage === null) {
            throw new InvalidPuzzleValues('Choose the image to upload.');
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
        if ($values->image === PuzzleImageChoice::Proposed) {
            $this->useProposedImage($proposedImage, $proposedImageRatio, $puzzle);
        }

        if ($values->image === PuzzleImageChoice::Upload) {
            $this->storeUploadedImage($values->uploadedImage, $puzzle);
        }

        return [
            'before' => $before,
            'after' => self::snapshot($puzzle),
        ];
    }

    /**
     * The record as the decision log keeps it - the shape the puzzle's history reads back (PuzzleHistoryChange).
     *
     * @return array<string, null|string|int>
     */
    public static function snapshot(Puzzle $puzzle): array
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

    private static function nullIfBlank(null|string $value): null|string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
