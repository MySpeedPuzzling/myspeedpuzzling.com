<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class RatingSnapshotRerankResult
{
    public function __construct(
        public int $ranked,
        public int $unranked,
    ) {
    }
}
