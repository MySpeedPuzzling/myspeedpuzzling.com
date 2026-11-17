<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One row of the "Get ready" checklist (a GetReadyItem's answer). $done: true ✓, false ○ (counted in "n of m done"),
 * null = a tip without a tick (not counted - nothing in the data can say it was done).
 */
readonly final class GetReadyRow
{
    /**
     * @param array<string, int|string> $labelParameters
     * @param array<string, string> $routeParameters
     * @param array<string, int|string> $noteParameters
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public null|bool $done,
        public array $labelParameters = [],
        public null|string $route = null,
        public array $routeParameters = [],
        public null|string $linkKey = null,
        public null|string $noteKey = null,
        public array $noteParameters = [],
    ) {
    }

    public function isTip(): bool
    {
        return $this->done === null;
    }
}
