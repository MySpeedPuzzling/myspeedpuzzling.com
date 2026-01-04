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

    public function generateFilename(
        string $brandName,
        string $puzzleName,
        int $piecesCount,
        string $extension,
    ): string {
        $slug = $this->slugger->slug(strtolower("$brandName-$puzzleName-$piecesCount"));
        $baseName = "$slug.$extension";

        // Check for duplicates and add UUID suffix if needed
        if ($this->filesystem->fileExists($baseName)) {
            $uuid = substr(Uuid::uuid7()->toString(), 0, 8);
            $baseName = "$slug-$uuid.$extension";
        }

        return $baseName;
    }
}
