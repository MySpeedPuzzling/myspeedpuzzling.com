<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\PruneResultImages;
use SpeedPuzzling\Web\Services\ResultImageCache;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Result share images older than ResultImageCache::KEEP_HOURS - whoever opens one later gets it redrawn.
 */
#[AsMessageHandler]
readonly final class PruneResultImagesHandler
{
    public function __construct(
        private ResultImageCache $resultImageCache,
    ) {
    }

    public function __invoke(PruneResultImages $message): int
    {
        return $this->resultImageCache->prune();
    }
}
