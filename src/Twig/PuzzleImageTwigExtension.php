<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class PuzzleImageTwigExtension extends AbstractExtension
{
    private const string PLACEHOLDER_IMAGE = '/img/placeholder-puzzle.png';

    public function __construct(
        readonly private ImageThumbnailTwigExtension $imageThumbnail,
    ) {
    }

    /**
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('puzzle_image', $this->getPuzzleImage(...)),
        ];
    }

    public function getPuzzleImage(null|string $path, string $filter): string
    {
        if ($path === null) {
            return self::PLACEHOLDER_IMAGE;
        }

        return $this->imageThumbnail->thumbnailUrl($path, $filter);
    }
}
