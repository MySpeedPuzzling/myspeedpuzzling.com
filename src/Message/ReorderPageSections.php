<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class ReorderPageSections
{
    /**
     * @param array<array{section: string, visible: bool}> $layout ordered list of "system-key" or "custom:<uuid>" entries
     */
    public function __construct(
        public null|string $competitionId,
        public null|string $seriesId,
        public array $layout,
    ) {
    }
}
