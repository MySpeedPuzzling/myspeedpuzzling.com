<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class ImageThumbnailTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly string $imageProvider,
        private readonly string $nginxProxyBaseUrl,
        private readonly string $imgproxyBucket,
        private readonly null|CacheManager $imagineCacheManager = null,
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
        return match ($this->imageProvider) {
            'imgproxy' => sprintf(
                '%s/preset:%s/plain/s3://%s/%s',
                $this->nginxProxyBaseUrl,
                $preset,
                $this->imgproxyBucket,
                ltrim($path, '/'),
            ),
            default => $this->imagineCacheManager?->getBrowserPath($path, $preset)
                ?? throw new \RuntimeException('Liip Imagine CacheManager not available'),
        };
    }
}
