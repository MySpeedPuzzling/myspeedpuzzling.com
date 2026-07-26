<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class NewsletterSyncDeletion
{
    public function __construct(
        public int $listmonkId,
        /** Full delete when the subscriber has no memberships outside the newsletter lists */
        public bool $fullDelete,
        /** @var list<int> newsletter lists to remove the subscriber from when not fully deleting */
        public array $removeFromListIds,
    ) {
    }
}
