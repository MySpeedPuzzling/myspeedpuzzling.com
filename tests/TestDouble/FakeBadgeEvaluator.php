<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use SpeedPuzzling\Web\Entity\Badge;
use SpeedPuzzling\Web\Services\Badges\BadgeEvaluator;

readonly final class FakeBadgeEvaluator extends BadgeEvaluator
{
    /**
     * @param list<Badge> $returnValue
     */
    public function __construct(private array $returnValue)
    {
        // Skip parent constructor — only recalculateForPlayer is exercised in tests.
    }

    public function recalculateForPlayer(string $playerId): array
    {
        return $this->returnValue;
    }
}
