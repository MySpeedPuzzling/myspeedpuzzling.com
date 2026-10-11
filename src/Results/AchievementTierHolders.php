<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\BadgeTier;

readonly final class AchievementTierHolders
{
    public function __construct(
        public BadgeTier $tier,
        /** @var list<AchievementHolder> the newest listed holders, newest first (the holders at launch last) */
        public array $holders,
        /** Every holder in the chosen country (all countries without a filter), listed or not */
        public int $totalCount,
        /** Every holder anywhere - the "of 82" next to the viewer's medal number */
        public int $allCount = 0,
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
