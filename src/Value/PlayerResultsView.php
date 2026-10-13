<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How a player's Results list its rows (docs/features/navigation README §4.7, PROF-05a): one row per puzzle (per puzzle
 * and pair/team on the Pairs and Teams tabs), led by the best attempt - or one row per result.
 */
enum PlayerResultsView: string
{
    case Puzzles = 'puzzles';
    case Every = 'every';

    public function translationKey(): string
    {
        return match ($this) {
            self::Puzzles => 'player_results.view.by_puzzle',
            self::Every => 'player_results.view.every_result',
        };
    }
}
