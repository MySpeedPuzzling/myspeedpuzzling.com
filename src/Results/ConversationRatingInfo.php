<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class ConversationRatingInfo
{
    public function __construct(
        public string $soldSwappedItemId,
        public bool $canRate,
        public null|int $myRatingStars,
    ) {
    }
}
