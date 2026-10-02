<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\LeaderboardChartView;

readonly final class ChangeLeaderboardChartView
{
    public function __construct(
        public string $playerId,
        public LeaderboardChartView $view,
    ) {
    }
}
