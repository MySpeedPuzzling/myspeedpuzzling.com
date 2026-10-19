<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\Insights\InsightsCriteria;
use SpeedPuzzling\Web\Value\InsightsLazyPart;

/**
 * Who asks for a lazy Insights part and with what (InsightsFrameRequest): always the signed-in player's own profile.
 */
readonly final class InsightsFrameContext
{
    public function __construct(
        public InsightsLazyPart $part,
        public PlayerProfile $profile,
        /** Active membership - a free trial counts */
        public bool $isMember,
        /** false when the player switched time predictions off (no expected times anywhere) */
        public bool $predictionsAllowed,
        /** The page's filters, only those the part reads, normalized (a free player's members-only values dropped) */
        public InsightsCriteria $criteria,
        /** The Turbo Frame's id - the page's placeholder carries the same (InsightsLazyPart::frameId()) */
        public string $frameId,
    ) {
    }
}
