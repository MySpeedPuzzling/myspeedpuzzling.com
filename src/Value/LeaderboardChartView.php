<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which chart a member sees above a puzzle leaderboard (docs/features/puzzle-leaderboard-chart.md): the distribution
 * of the times, or the ranking - one bar per row, fastest first. Chosen with the switch in the chart's corner,
 * persisted per player and applied to every puzzle page and tab.
 */
enum LeaderboardChartView: string
{
    case Distribution = 'distribution';
    case Ranking = 'ranking';
}
