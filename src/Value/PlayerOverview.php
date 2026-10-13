<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A player's Overview, the profile's first tab (docs/features/navigation README §4.7, PROF-03): it shows the latest
 * results, the Results page shows all of them.
 */
final class PlayerOverview
{
    /**
     * Latest results on the Overview. A player with no more results than this has nothing on Results the Overview does
     * not show already: the Results page then stays out of search engines (PROF-07)
     */
    public const int LATEST_RESULTS = 8;
}
