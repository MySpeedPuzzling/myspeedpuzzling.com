<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PiecesHubBrand
{
    public function __construct(
        public string $brandName,
        public string $slug,
        public int $solvesCount,
    ) {
    }
}
