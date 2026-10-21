<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use League\Flysystem\Filesystem;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Query\IsPuzzleKeptSecret;
use SpeedPuzzling\Web\Value\UploadedImageMetadata;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Puts a new image on a puzzle - a moderator's uploaded photo, a change request's proposed one or an earlier picture
 * restored from the history - under a file name
 * built from the puzzle's brand, name and pieces: call it after those are final. Used by PuzzleRecordUpdater and the
 * merge review (ApprovePuzzleMergeRequestHandler). What the picture's file carried when uploaded comes along with it
 * (Puzzle::useImage(), docs/features/upload-image-metadata.md).
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

        $uploadMetadata = $this->imageOptimizer->optimize($uploadedImage->getPathname());
        $imageRatio = $this->imageOptimizer->getImageRatio($uploadedImage->getPathname());

        // Stream is better because it is memory safe
        $stream = fopen($uploadedImage->getPathname(), 'rb');
        $this->filesystem->writeStream($newImagePath, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        $puzzle->useImage($newImagePath, $imageRatio, $uploadMetadata);
    }

    public function useProposed(
        string $proposedImage,
        null|float $proposedImageRatio,
        Puzzle $puzzle,
        null|UploadedImageMetadata $proposedImageUploadMetadata = null,
    ): void {
        $newImagePath = $this->newImagePath($puzzle, pathinfo($proposedImage, PATHINFO_EXTENSION) ?: 'jpg');

        $this->filesystem->copy($proposedImage, $newImagePath);
        $this->filesystem->delete($proposedImage);

        $puzzle->useImage($newImagePath, $proposedImageRatio, $proposedImageUploadMetadata);
    }

    /**
     * Puts an earlier picture back (RestorePuzzleImageHandler): a copy under a name never written before - the image
     * caches key on the name, and the new name gives the picture a new fingerprint, so its analyses run again. The
     * stored object it is copied from stays where it is. What its file carried when uploaded went with it when it was
     * replaced - not known for the copy.
     */
    public function useCopyOf(string $storedImage, null|float $imageRatio, Puzzle $puzzle): void
    {
        $newImagePath = $this->newImagePath($puzzle, pathinfo($storedImage, PATHINFO_EXTENSION) ?: 'jpg');

        $this->filesystem->copy($storedImage, $newImagePath);

        $puzzle->useImage($newImagePath, $imageRatio, null);
    }

    private function newImagePath(Puzzle $puzzle, string $extension): string
    {
        // A secret puzzle's picture must not be found by guessing its file name from the public name and id
        if ($this->isPuzzleKeptSecret->byId($puzzle->id->toString())) {
            return $this->puzzleImageNamer->secretFilename($extension);
        }

        // Never a name written before - the image caches would keep the old picture's thumbnails
        return $this->puzzleImageNamer->generateFilename(
            $puzzle->manufacturer !== null ? $puzzle->manufacturer->name : 'puzzle',
            $puzzle->name,
            $puzzle->piecesCount,
            $puzzle->id->toString(),
            $extension,
        );
    }
}
