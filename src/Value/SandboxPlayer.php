<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * A generated sandbox player as the simulation sees it (GetSandboxSimulationData::players()).
 */
final readonly class SandboxPlayer
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $code,
        public DateTimeImmutable $registeredAt,
    ) {
    }
}
