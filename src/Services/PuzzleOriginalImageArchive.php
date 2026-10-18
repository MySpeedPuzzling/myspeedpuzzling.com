<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use League\Flysystem\Filesystem;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\PuzzleOriginalImageNotArchived;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Throwable;

/**
 * Keeps a puzzle's picture before an automated proposal replaces it (docs/features/insights/README.md "Artwork crops":
 * the original picture is always kept): a copy at `originals/<puzzleId>/<fingerprint>.<ext>` on the same storage. An
 * approved image change never deletes the old object anyway (PuzzleImageStorage writes the new picture under a new
 * name); the archive is the copy nothing else ever renames or deletes, under a path derived from the puzzle alone.
 *
 * The same picture always lands on the same path, so a second copy of it only overwrites it with the same bytes.
 */
readonly final class PuzzleOriginalImageArchive
{
    public const string DIRECTORY = 'originals';

    public function __construct(
        private Filesystem $filesystem,
        private LoggerInterface $logger,
    ) {
    }

    public static function pathFor(string $puzzleId, string $image): string
    {
        $extension = strtolower(pathinfo($image, PATHINFO_EXTENSION));

        return sprintf(
            '%s/%s/%s%s',
            self::DIRECTORY,
            strtolower($puzzleId),
            PuzzleImageFingerprint::of($image),
            $extension !== '' ? '.' . $extension : '',
        );
    }

    /**
     * The archive path of the puzzle's picture now. Call it before the picture is switched.
     *
     * @throws PuzzleOriginalImageNotArchived the copy failed - the picture must not be replaced
     */
    public function archive(Puzzle $puzzle): string
    {
        $image = $puzzle->image;
        assert($image !== null);

        $path = self::pathFor($puzzle->id->toString(), $image);

        try {
            $this->filesystem->copy($image, $path);
        } catch (Throwable $e) {
            // A person should look: storage refused a copy, and an approval waits for it
            $this->logger->warning('The puzzle picture could not be copied to the originals archive - the approval was refused', [
                'puzzle_id' => $puzzle->id->toString(),
                'image' => $image,
                'archive_path' => $path,
                'exception' => $e,
            ]);

            throw new PuzzleOriginalImageNotArchived($image, $e);
        }

        return $path;
    }
}
