<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A change refused because of the round's puzzle format (RoundPuzzleFormat, competition toolkit A2) - nothing was
 * changed:
 * - `to_solve_range`: "pick K" needs 1 ≤ K ≤ the round's puzzles (add the puzzles first);
 * - `too_few_puzzles`: removing a puzzle would leave the round fewer puzzles than its entries pick (lower K first);
 * - `online_timing`: a round using online timing stays "one result for the round";
 * - `puzzle_in_results`: a puzzle the per-puzzle record of `entries` names is not removed (competition toolkit A3);
 * - `conflicts_with_results`: the new format or K would break the per-puzzle record of `entries` (A3) - clear or
 *   correct those first.
 * `reason` is a key of competition_toolkit.round_format.refused.*.
 */
final class RoundPuzzleFormatRefused extends ConflictHttpException
{
    public const string TO_SOLVE_RANGE = 'to_solve_range';
    public const string TOO_FEW_PUZZLES = 'too_few_puzzles';
    public const string ONLINE_TIMING = 'online_timing';
    public const string PUZZLE_IN_RESULTS = 'puzzle_in_results';
    public const string CONFLICTS_WITH_RESULTS = 'conflicts_with_results';

    public function __construct(
        readonly public string $reason,
        readonly public int $puzzlesToSolve,
        readonly public int $puzzlesCount,
        // The entries whose per-puzzle record is in the way (puzzle_in_results, conflicts_with_results)
        readonly public int $entries = 0,
    ) {
        parent::__construct(sprintf('Refused by the round\'s puzzle format (%s: pick %d of %d puzzles). Nothing was changed.', $reason, $puzzlesToSolve, $puzzlesCount));
    }

    public function translationKey(): string
    {
        return 'competition_toolkit.round_format.refused.' . $this->reason;
    }

    /**
     * @return array{'%count%': int, '%puzzles%': int, '%entries%': int}
     */
    public function translationParameters(): array
    {
        return ['%count%' => $this->puzzlesToSolve, '%puzzles%' => $this->puzzlesCount, '%entries%' => $this->entries];
    }
}
