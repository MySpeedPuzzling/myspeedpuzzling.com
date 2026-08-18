<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class NewsletterSyncListUnsubscribe
{
    public function __construct(
        public int $listmonkId,
        /** @var list<int> */
        public array $listIds,
    ) {
    }
}
