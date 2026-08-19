<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\BadgeTier;

readonly final class AchievementTierHolders
{
    public function __construct(
        public BadgeTier $tier,
        /** @var list<AchievementHolder> */
        public array $holders,
        public int $totalCount,
    ) {
    }

    /**
     * Everyone counts, but only members with public profiles are listed —
     * this is the "+N more puzzlers" number.
     */
    public function hiddenCount(): int
    {
        return max(0, $this->totalCount - count($this->holders));
    }
}
