<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\PuzzleTextSearch;

/**
 * The search box of a player's Results (docs/features/navigation PROF-05d): which of the player's puzzles a typed
 * text finds - the one puzzle text search of the site (PuzzleTextSearch: every name, other names included, EANs with
 * or without leading zeros, brand codes and their check-digit aliases), narrowed to the puzzles the player has results
 * for. One statement, only when something was typed; PlayerResultsBuilder keeps the rows of the puzzles found.
 *
 * Reads the puzzle table only: the player's results (blocked co-solvers, private profiles, suspicious times) are
 * decided by GetPlayerSolvedPuzzles, whose rows hand in the ids.
 */
readonly final class GetPlayerResultsSearchMatches
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $puzzleIds the player's puzzles
     * @return array<string, true> the ids found, as keys
     */
    public function puzzleIds(string $search, array $puzzleIds): array
    {
        $textSearch = PuzzleTextSearch::fromUserInput($search);
        $puzzleIds = array_values(array_unique(array_filter($puzzleIds, static fn (string $id): bool => Uuid::isValid($id))));

        if ($textSearch->isEmpty() || $puzzleIds === []) {
            return [];
        }

        // One array parameter whatever the history's size (2,000+ puzzles) - the plan stays the same
        $query = <<<SQL
SELECT p.id
FROM puzzle p
WHERE p.id = ANY(CAST(:puzzleIds AS uuid[]))
  AND {$textSearch->condition('p')}
SQL;

        $ids = $this->database
            ->executeQuery($query, [
                'puzzleIds' => '{' . implode(',', $puzzleIds) . '}',
                ...$textSearch->parameters($this->clock->now()),
            ])
            ->fetchFirstColumn();

        $found = [];

        foreach ($ids as $id) {
            if (is_string($id)) {
                $found[$id] = true;
            }
        }

        return $found;
    }
}
