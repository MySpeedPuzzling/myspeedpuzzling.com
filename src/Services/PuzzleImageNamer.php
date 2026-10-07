<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Symfony\Component\String\Slugger\SluggerInterface;

readonly final class PuzzleImageNamer
{
    public function __construct(
        private SluggerInterface $slugger,
    ) {
    }

    /**
     * SEO filename: "{manufacturer-slug}-{name-slug}-{pieces}-{shortId}-{random}.{ext}" - a name never written before.
     * The image caches key on the name (images-cache nginx 365 days, Cloudflare `immutable`, see ImageCachePurgeList),
     * so a name written twice would keep showing the first picture's thumbnails. The random part comes from
     * random_bytes(): a uuid7 prefix is a timestamp, the same for about a minute, and the shortId (the first 8 chars of
     * the puzzle's uuid7) is one too.
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
        $random = bin2hex(random_bytes(4));

        return "$slug-$shortId-$random.$extension";
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
