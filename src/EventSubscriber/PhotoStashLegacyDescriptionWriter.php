<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Services\PhotoStash\PhotoStash;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Release 1 of 2 (docs/TODO.md "PhotoStash descriptions in Postgres"): the old-format descriptions of the
 * reservations made in this request, written after the answer - a container of the previous release, still serving
 * during the blue-green rollout, then finds the photo when the form lands there. Removed in release 2.
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
readonly final class PhotoStashLegacyDescriptionWriter
{
    public function __construct(
        private PhotoStash $photoStash,
    ) {
    }

    public function __invoke(): void
    {
        $this->photoStash->writeLegacyDescriptions();
    }
}
