<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Query\IsPuzzleKeptSecret;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Puts a new image on a puzzle - a moderator's uploaded photo or a change request's proposed one - under a file name
 * built from the puzzle's brand, name and pieces: call it after those are final. Used by PuzzleRecordUpdater and the
 * merge review (ApprovePuzzleMergeRequestHandler).
 */
readonly final class PuzzleImageStorage
{
    public function __construct(
        private Filesystem $filesystem,
        private PuzzleImageNamer $puzzleImageNamer,
        private ImageOptimizer $imageOptimizer,
        private IsPuzzleKeptSecret $isPuzzleKeptSecret,
    ) {
    }

    public function storeUploaded(UploadedFile $uploadedImage, Puzzle $puzzle): void
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

    public function useProposed(string $proposedImage, null|float $proposedImageRatio, Puzzle $puzzle): void
    {
        $newImagePath = $this->newImagePath($puzzle, pathinfo($proposedImage, PATHINFO_EXTENSION) ?: 'jpg');

        $this->filesystem->copy($proposedImage, $newImagePath);
        $this->filesystem->delete($proposedImage);

        $puzzle->image = $newImagePath;
        $puzzle->imageRatio = $proposedImageRatio;
    }

    private function newImagePath(Puzzle $puzzle, string $extension): string
    {
        // A secret puzzle's picture must not be found by guessing its file name from the public name and id
        if ($this->isPuzzleKeptSecret->byId($puzzle->id->toString())) {
            return $this->puzzleImageNamer->secretFilename($extension);
        }

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
}
