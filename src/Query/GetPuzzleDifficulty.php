<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PuzzleDifficultyResult;

readonly final class GetPuzzleDifficulty
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function byPuzzleId(string $puzzleId): null|PuzzleDifficultyResult
    {
        $query = <<<SQL
SELECT
    pd.puzzle_id,
    pd.difficulty_score,
    pd.difficulty_tier,
    pd.confidence,
    pd.sample_size,
    pd.memorability_score,
    pd.skill_sensitivity_score,
    pd.predictability_score,
    pd.box_dependence_score,
    pd.improvement_ceiling_score
FROM puzzle_difficulty pd
WHERE pd.puzzle_id = :puzzleId
SQL;

        /** @var array{puzzle_id: string, difficulty_score: null|float|string, difficulty_tier: null|int|string, confidence: string, sample_size: int|string, memorability_score: null|float|string, skill_sensitivity_score: null|float|string, predictability_score: null|float|string, box_dependence_score: null|float|string, improvement_ceiling_score: null|float|string}|false $row */
        $row = $this->database->executeQuery($query, [
            'puzzleId' => $puzzleId,
        ])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return PuzzleDifficultyResult::fromDatabaseRow($row);
    }

    /**
     * @param list<string> $puzzleIds
     *
     * @return array<string, PuzzleDifficultyResult>
     */
    public function forPuzzleList(array $puzzleIds): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT
    pd.puzzle_id,
    pd.difficulty_score,
    pd.difficulty_tier,
    pd.confidence,
    pd.sample_size,
    pd.memorability_score,
    pd.skill_sensitivity_score,
    pd.predictability_score,
    pd.box_dependence_score,
    pd.improvement_ceiling_score
FROM puzzle_difficulty pd
WHERE pd.puzzle_id = ANY(:puzzleIds)
SQL;

        /** @var list<array{puzzle_id: string, difficulty_score: null|float|string, difficulty_tier: null|int|string, confidence: string, sample_size: int|string, memorability_score: null|float|string, skill_sensitivity_score: null|float|string, predictability_score: null|float|string, box_dependence_score: null|float|string, improvement_ceiling_score: null|float|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'puzzleIds' => '{' . implode(',', $puzzleIds) . '}',
        ])->fetchAllAssociative();

        $results = [];

        foreach ($rows as $row) {
            $result = PuzzleDifficultyResult::fromDatabaseRow($row);
            $results[$result->puzzleId] = $result;
        }

        return $results;
    }
}
