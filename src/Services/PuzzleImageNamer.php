<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Component\String\Slugger\SluggerInterface;

readonly final class PuzzleImageNamer
{
    public function __construct(
        private Filesystem $filesystem,
        private SluggerInterface $slugger,
    ) {
    }

    /**
     * SEO filename: "{manufacturer-slug}-{name-slug}-{pieces}-{shortId}.{ext}".
     * The shortId (first 8 chars of the puzzle uuid) makes names collision-proof
     * across puzzles that share manufacturer + name + pieces count.
     */
    public function generateFilename(
        string $brandName,
        string $puzzleName,
        int $piecesCount,
        string $puzzleId,
        string $extension,
    ): string {
        $slug = $this->slugger->slug(strtolower("$brandName-$puzzleName-$piecesCount"));
        $shortId = substr($puzzleId, 0, 8);
        $baseName = "$slug-$shortId.$extension";

        // Same puzzle re-uploading an image produces the same deterministic
        // name - add a random suffix so browser caches never serve stale files
        if ($this->filesystem->fileExists($baseName)) {
            $uuid = substr(Uuid::uuid7()->toString(), 0, 8);
            $baseName = "$slug-$shortId-$uuid.$extension";
        }

        return $baseName;
    }

    /**
     * A puzzle created secret (a competition round keeps it hidden): nothing in the file name can be guessed from its
     * name, brand or id - with "hide image only" those are public while the picture is not.
     */
    public function secretFilename(string $extension): string
    {
        return bin2hex(random_bytes(16)) . '.' . $extension;
    }

    public static function isSecretFilename(string $path): bool
    {
        return preg_match('/^[0-9a-f]{32}\.\w+$/', $path) === 1;
    }
}
