<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class AdminRemoveListing
{
    public function __construct(
        public string $sellSwapListItemId,
        public string $adminId,
        public null|string $reason = null,
        public null|string $reportId = null,
    ) {
    }
}
