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
        /** Holders that may be listed (members with public profiles, in the chosen country, not blocked) */
        public int $listedCount = 0,
    ) {
    }

    /** Listed holders not shown yet - "Show 100 more" / "Show all N" */
    public function moreListed(): int
    {
        return max(0, $this->listedCount - count($this->holders));
    }

    /** Holders that are counted but never listed (private profiles, non-members, opted out) */
    public function unlistedCount(): int
    {
        return max(0, $this->totalCount - $this->listedCount);
    }
}
