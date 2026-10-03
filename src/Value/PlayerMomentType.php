<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Things that happened to a player, detected by the community stats cron (docs/features/players-page/README.md).
 * The Players page shows them as chips on people; the future Hub feed shows them as items.
 */
enum PlayerMomentType: string
{
    // A solo time that beats every earlier solo time of the player on the same piece count
    case PersonalBest = 'personal_best';
    // The result that brought the player's number of results to a milestone (PlayerMomentType::PUZZLE_MILESTONES)
    case PuzzlesMilestone = 'puzzles_milestone';
    // The result that took the player's placed pieces across a milestone (PlayerMomentType::PIECES_MILESTONES)
    case PiecesMilestone = 'pieces_milestone';
    // The player's very first result
    case FirstResult = 'first_result';

    public const array PUZZLE_MILESTONES = [50, 100, 250, 500, 1000, 1500, 2000, 2500, 3000, 4000, 5000];

    public const array PIECES_MILESTONES = [100_000, 250_000, 500_000, 1_000_000, 2_000_000, 3_000_000, 5_000_000, 10_000_000];
}
