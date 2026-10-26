<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Results\PlayerRanking;

/**
 * The profile results' placing chips (feature request #292, docs/features/profile-results-placing.md): one at a time,
 * by the player's rank on each puzzle's solo leaderboard. The share chips count only boards a share is shown for
 * (RankShare::MIN_TOTAL) - "top 10 %" of three people says nothing. A puzzle without a rank never matches.
 */
enum PlacingFilter: string
{
    case Top3 = 'top3';
    case Top10 = 'top10';
    case Top10Percent = 'top10_percent';
    case Top25Percent = 'top25_percent';
    case BottomHalf = 'bottom_half';

    public function matches(null|PlayerRanking $ranking): bool
    {
        if ($ranking === null) {
            return false;
        }

        $rank = $ranking->rank;
        $total = $ranking->totalPlayers;

        return match ($this) {
            self::Top3 => $rank <= 3,
            self::Top10 => $rank <= 10,
            // Integer maths: "#10 of 100" is exactly 10 %
            self::Top10Percent => $total >= RankShare::MIN_TOTAL && 10 * $rank <= $total,
            self::Top25Percent => $total >= RankShare::MIN_TOTAL && 4 * $rank <= $total,
            self::BottomHalf => $total >= RankShare::MIN_TOTAL && 2 * $rank > $total,
        };
    }
}
