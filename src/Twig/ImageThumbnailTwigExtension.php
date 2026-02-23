<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class ImageThumbnailTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly string $nginxProxyBaseUrl,
    ) {
    }

    /**
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('thumbnail', $this->thumbnailUrl(...)),
        ];
    }

    public function thumbnailUrl(string $path, string $preset): string
    {
        return sprintf(
            '%s/preset:%s/plain/%s',
            $this->nginxProxyBaseUrl,
            $preset,
            ltrim($path, '/'),
        );
    }
}
