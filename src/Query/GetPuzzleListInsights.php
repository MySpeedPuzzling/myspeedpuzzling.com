<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PuzzleListInsight;
use SpeedPuzzling\Web\Value\DifficultyTier;

/**
 * One query for a whole puzzle list, however long: primary-key lookups on
 * puzzle, puzzle_statistics and (only when asked) puzzle_difficulty.
 */
readonly final class GetPuzzleListInsights
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param array<string> $puzzleIds duplicates are fine
     *
     * @return array<string, PuzzleListInsight>
     */
    public function forPuzzles(array $puzzleIds, bool $withDifficulty): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $difficultyColumn = $withDifficulty ? 'pd.difficulty_tier' : 'NULL::int';
        $difficultyJoin = $withDifficulty ? 'LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = p.id' : '';

        $query = <<<SQL
SELECT
    p.id AS puzzle_id,
    COALESCE(ps.solved_times_count, 0) AS solved_times,
    {$difficultyColumn} AS difficulty_tier
FROM puzzle p
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = p.id
{$difficultyJoin}
WHERE p.id = ANY(:puzzleIds)
SQL;

        /** @var list<array{puzzle_id: string, solved_times: int|string, difficulty_tier: null|int|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'puzzleIds' => '{' . implode(',', array_unique($puzzleIds)) . '}',
        ])->fetchAllAssociative();

        $insights = [];

        foreach ($rows as $row) {
            $insights[$row['puzzle_id']] = new PuzzleListInsight(
                solvedTimes: (int) $row['solved_times'],
                difficultyTier: $row['difficulty_tier'] === null ? null : DifficultyTier::tryFrom((int) $row['difficulty_tier']),
            );
        }

        return $insights;
    }
}
