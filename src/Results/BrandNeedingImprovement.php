<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class BrandNeedingImprovement
{
    public function __construct(
        public string $manufacturerId,
        public string $manufacturerName,
        public int $puzzlesCount,
    ) {
    }
}
