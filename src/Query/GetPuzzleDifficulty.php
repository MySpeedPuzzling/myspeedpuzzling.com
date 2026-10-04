<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PuzzleDifficultyRating;
use SpeedPuzzling\Web\Results\PuzzleDifficultyResult;
use SpeedPuzzling\Web\Value\DifficultyTier;

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
    pd.improvement_ceiling_score,
    pd.indices_p25,
    pd.indices_p75
FROM puzzle_difficulty pd
WHERE pd.puzzle_id = :puzzleId
SQL;

        /** @var array{puzzle_id: string, difficulty_score: null|float|string, difficulty_tier: null|int|string, confidence: string, sample_size: int|string, memorability_score: null|float|string, skill_sensitivity_score: null|float|string, predictability_score: null|float|string, box_dependence_score: null|float|string, improvement_ceiling_score: null|float|string, indices_p25: null|float|string, indices_p75: null|float|string}|false $row */
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
    pd.improvement_ceiling_score,
    pd.indices_p25,
    pd.indices_p75
FROM puzzle_difficulty pd
WHERE pd.puzzle_id = ANY(:puzzleIds)
SQL;

        /** @var list<array{puzzle_id: string, difficulty_score: null|float|string, difficulty_tier: null|int|string, confidence: string, sample_size: int|string, memorability_score: null|float|string, skill_sensitivity_score: null|float|string, predictability_score: null|float|string, box_dependence_score: null|float|string, improvement_ceiling_score: null|float|string, indices_p25: null|float|string, indices_p75: null|float|string}> $rows */
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

    /**
     * Just the tier of every rated puzzle among these - a puzzle missing from the result is not rated yet.
     * For lists that need the tier of a player's whole history (the profile results): the tier-only rows
     * cost ~1 ms for the heaviest solver (2,159 puzzles), joining the statistics too ~12 ms (dev copy, 2026-10-03).
     *
     * @param array<string> $puzzleIds duplicates are fine
     *
     * @return array<string, DifficultyTier>
     */
    public function tiersOf(array $puzzleIds): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT pd.puzzle_id, pd.difficulty_tier
FROM puzzle_difficulty pd
WHERE pd.puzzle_id = ANY(:puzzleIds)
    AND pd.difficulty_tier IS NOT NULL
SQL;

        /** @var list<array{puzzle_id: string, difficulty_tier: int|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'puzzleIds' => '{' . implode(',', array_unique($puzzleIds)) . '}',
        ])->fetchAllAssociative();

        $tiers = [];

        foreach ($rows as $row) {
            $tier = DifficultyTier::tryFrom((int) $row['difficulty_tier']);

            if ($tier !== null) {
                $tiers[$row['puzzle_id']] = $tier;
            }
        }

        return $tiers;
    }

    /**
     * tiersOf() plus the score, for lists that can be sorted by difficulty. The score comes from the same rows,
     * so it costs nothing extra (heaviest history: 1.10 ms vs 1.07 ms, dev copy 2026-10-04).
     *
     * @param array<string> $puzzleIds duplicates are fine
     *
     * @return array<string, PuzzleDifficultyRating> a puzzle missing from the result is not rated yet
     */
    public function ratingsOf(array $puzzleIds): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT pd.puzzle_id, pd.difficulty_tier, pd.difficulty_score
FROM puzzle_difficulty pd
WHERE pd.puzzle_id = ANY(:puzzleIds)
    AND pd.difficulty_tier IS NOT NULL
    AND pd.difficulty_score IS NOT NULL
SQL;

        /** @var list<array{puzzle_id: string, difficulty_tier: int|string, difficulty_score: float|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'puzzleIds' => '{' . implode(',', array_unique($puzzleIds)) . '}',
        ])->fetchAllAssociative();

        $ratings = [];

        foreach ($rows as $row) {
            $tier = DifficultyTier::tryFrom((int) $row['difficulty_tier']);

            if ($tier !== null) {
                $ratings[$row['puzzle_id']] = new PuzzleDifficultyRating($tier, (float) $row['difficulty_score']);
            }
        }

        return $ratings;
    }
}
