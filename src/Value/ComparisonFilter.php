<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class ComparisonFilter
{
    public function __construct(
        public string $search = '',
        public string $manufacturerId = '',
        public null|int $pieces = null,
        public string $sort = 'name',
        public bool $onlyCommon = false,
        public string $baselineKey = '',
    ) {
    }
}
