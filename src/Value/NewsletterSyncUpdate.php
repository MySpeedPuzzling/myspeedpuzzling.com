<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class NewsletterSyncUpdate
{
    public function __construct(
        public int $listmonkId,
        public DesiredNewsletterSubscriber $desired,
        /** @var list<int> full replacement set of list ids (foreign lists preserved) */
        public array $targetListIds,
    ) {
    }
}
