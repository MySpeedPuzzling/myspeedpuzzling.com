<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Rebuilds the Players page's precomputed numbers and detects player moments (docs/features/players-page/README.md).
 * Dispatched by `myspeedpuzzling:recalculate-community-stats` every 15 minutes.
 */
readonly final class RecalculateCommunityStats
{
}
