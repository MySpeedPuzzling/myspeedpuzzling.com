<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\ComparisonTimeRow;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Value\ComparisonCriteria;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonLimits;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use SpeedPuzzling\Web\Value\ComparisonTimes;

/**
 * The one aggregate statement of the comparison page (docs/features/player-comparison.md "Data rules", "Performance
 * budgets"): every (subject, puzzle) of up to ten subjects of one kind, filters applied. Its cost does not depend on the
 * page shown - the builder ranks, sorts, summarises and pages in PHP, the shown puzzles are hydrated by
 * GetComparisonPuzzles.
 *
 * - Solo subject: the player's solo times. Pair/team subject: the times of exactly that set of people
 *   (`puzzling_team_id`), whoever tracked them.
 * - Valid time: not suspicious, with a time. Day = COALESCE(finished_at, tracked_at).
 * - The period and the first-tries filter drop times *before* aggregating ("best" = best within the filters); puzzle
 *   filters (hidden puzzle, pieces, brand, difficulty) then drop whole puzzles, and "puzzles to show" keeps a puzzle
 *   only when enough subjects are left on it - the same rule the builder applies, done here so a big line-up does not
 *   carry thousands of one-subject rows into PHP.
 * - Best: fastest, then earliest day, tracked_at, id. First try: the earliest time flagged as one (legacy duplicates).
 *
 * Measured on the production copy (2026-10-03, warm): ten heaviest players (14.9k times, 8,905 subject×puzzle rows):
 * "2+" (5,167 rows kept) 40 ms, "all" 49 ms, first tries + 12 months + 500-1000 pc + 3 brands + 3 tiers 17 ms; ten
 * heaviest pairs 15 ms; two typical players ~2 ms. Estimated cost ~20k - far below jit_above_cost; keep it there.
 * The difficulty tier (members' Charts tab, difficulty filter/sort) costs the ten heaviest +3 ms, about 7 % ("2+" 41 → 44
 * ms, "all" 43 → 46.5 ms, medians of 25 runs), two typical players +0.3 ms.
 *
 * Pass only subjects GetComparisonSubjects reported available: this statement does not decide who may be compared. It
 * still hides the times of players the viewer has hidden (HiddenPlayers), which costs nothing when there are none.
 */
readonly final class GetComparisonResults
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
    ) {
    }

    /**
     * @param list<ComparisonSubjectRef> $refs subjects of the given kind - players for Solo, pairs/teams otherwise; refs of
     *                                         the other type are ignored, at most ComparisonLimits::MEMBER are used
     * @param bool $withPuzzleNames also select the puzzle name (sort by name - see ComparisonCriteria::needsPuzzleNames() -
     *                              or the charts, which label every puzzle)
     * @param bool $withDifficulty also select the difficulty tier (the members' "By difficulty" chart); the criteria ask for
     *                             it on their own when a member filters or sorts by difficulty - either way it is the same
     *                             one join, never another statement
     * @return list<ComparisonTimeRow> unordered
     */
    public function forSubjects(ComparisonKind $kind, array $refs, ComparisonCriteria $criteria, bool $withPuzzleNames = false, bool $withDifficulty = false): array
    {
        $isSolo = $kind === ComparisonKind::Solo;
        $subjectIds = [];

        foreach ($refs as $ref) {
            if ($ref->isPlayer() === $isSolo) {
                $subjectIds[$ref->id] = true;
            }
        }

        $subjectIds = array_slice(array_keys($subjectIds), 0, ComparisonLimits::MEMBER);

        if ($subjectIds === []) {
            return [];
        }

        $now = $this->clock->now();
        $parameters = [
            'subjectIds' => $subjectIds,
            'now' => $now->format('Y-m-d H:i:s'),
            'minSolvers' => $criteria->show->minimumSolvers(count($subjectIds)),
        ];
        $types = [
            'subjectIds' => ArrayParameterType::STRING,
            'minSolvers' => ParameterType::INTEGER,
        ];

        if ($isSolo) {
            $subjectColumn = 'pst.player_id';
            $subjectCondition = "pst.player_id IN (:subjectIds) AND pst.puzzling_type = 'solo'";
            $notHidden = $this->hiddenPlayers->sqlExclude('pst.player_id');
        } else {
            $subjectColumn = 'pst.puzzling_team_id';
            $subjectCondition = 'pst.puzzling_team_id IN (:subjectIds)';
            $notHidden = $this->hiddenPlayers->sqlExcludeTeam('pst.team');
        }

        $timeConditions = '';

        if ($criteria->times === ComparisonTimes::FirstTries) {
            $timeConditions .= ' AND pst.first_attempt = true';
        }

        $solvedFrom = $criteria->solvedFrom($now);

        if ($solvedFrom !== null) {
            $timeConditions .= ' AND COALESCE(pst.finished_at, pst.tracked_at) >= CAST(:solvedFrom AS TIMESTAMP)';
            $parameters['solvedFrom'] = $solvedFrom->format('Y-m-d H:i:s');
        }

        $solvedBefore = $criteria->solvedBefore();

        if ($solvedBefore !== null) {
            $timeConditions .= ' AND COALESCE(pst.finished_at, pst.tracked_at) < CAST(:solvedBefore AS TIMESTAMP)';
            $parameters['solvedBefore'] = $solvedBefore->format('Y-m-d H:i:s');
        }

        $puzzleConditions = '';

        if ($criteria->pieces->minPieces !== null) {
            $puzzleConditions .= ' AND puzzle.pieces_count >= :minPieces';
            $parameters['minPieces'] = $criteria->pieces->minPieces;
            $types['minPieces'] = ParameterType::INTEGER;
        }

        if ($criteria->pieces->maxPieces !== null) {
            $puzzleConditions .= ' AND puzzle.pieces_count <= :maxPieces';
            $parameters['maxPieces'] = $criteria->pieces->maxPieces;
            $types['maxPieces'] = ParameterType::INTEGER;
        }

        if ($criteria->brandIds !== []) {
            $puzzleConditions .= ' AND puzzle.manufacturer_id IN (:brandIds)';
            $parameters['brandIds'] = $criteria->brandIds;
            $types['brandIds'] = ArrayParameterType::STRING;
        }

        $withDifficulty = $withDifficulty || $criteria->needsDifficulty();
        $difficultyJoin = '';
        $difficultyColumn = 'NULL::INT AS difficulty_tier';

        if ($withDifficulty) {
            // Only rated rows: about a sixth of puzzle_difficulty has a tier, so the hash this join builds is a sixth too
            // (ten heaviest players: +3 ms instead of +6-7 ms for the whole table). A row without a tier reads as NULL
            // either way, so "not rated yet" (IS NULL) means the same.
            $difficultyJoin = 'LEFT JOIN puzzle_difficulty ON puzzle_difficulty.puzzle_id = puzzle.id AND puzzle_difficulty.difficulty_tier IS NOT NULL';
            $difficultyColumn = 'puzzle_difficulty.difficulty_tier';
            $puzzleConditions .= self::difficultyCondition($criteria->difficultyTiers, $parameters, $types);
        }

        $nameColumn = $withPuzzleNames ? 'puzzle.name AS puzzle_name' : 'NULL AS puzzle_name';

        // Two numberings instead of six ordered array_agg()s: one sort per window, then plain FILTERed aggregates
        $query = <<<SQL
WITH ranked AS (
    SELECT
        {$subjectColumn} AS subject_id,
        pst.puzzle_id,
        pst.id,
        pst.seconds_to_solve,
        pst.first_attempt,
        COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
        ROW_NUMBER() OVER (
            PARTITION BY {$subjectColumn}, pst.puzzle_id
            ORDER BY pst.seconds_to_solve, COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at, pst.id
        ) AS best_rank,
        ROW_NUMBER() OVER (
            PARTITION BY {$subjectColumn}, pst.puzzle_id, pst.first_attempt
            ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at, pst.id
        ) AS chronological_rank
    FROM puzzle_solving_time pst
    WHERE {$subjectCondition}
        AND pst.suspicious = false
        AND pst.seconds_to_solve IS NOT NULL
        {$timeConditions}
        {$notHidden}
),
per_subject AS (
    SELECT
        subject_id,
        puzzle_id,
        COUNT(*) AS attempts,
        MIN(seconds_to_solve) AS best_seconds,
        (ARRAY_AGG(id) FILTER (WHERE best_rank = 1))[1] AS best_time_id,
        MAX(solved_at) FILTER (WHERE best_rank = 1) AS best_day,
        MAX(seconds_to_solve) FILTER (WHERE first_attempt AND chronological_rank = 1) AS first_try_seconds,
        (ARRAY_AGG(id) FILTER (WHERE first_attempt AND chronological_rank = 1))[1] AS first_try_time_id,
        MAX(solved_at) FILTER (WHERE first_attempt AND chronological_rank = 1) AS first_try_day
    FROM ranked
    GROUP BY subject_id, puzzle_id
),
counted AS (
    SELECT
        per_subject.*,
        puzzle.pieces_count,
        {$difficultyColumn},
        {$nameColumn},
        COUNT(*) OVER (PARTITION BY per_subject.puzzle_id) AS solvers
    FROM per_subject
    INNER JOIN puzzle ON puzzle.id = per_subject.puzzle_id
    {$difficultyJoin}
    WHERE (puzzle.hide_until IS NULL OR puzzle.hide_until <= CAST(:now AS TIMESTAMP))
        {$puzzleConditions}
)
SELECT
    subject_id,
    puzzle_id,
    pieces_count,
    attempts,
    best_seconds,
    best_time_id,
    best_day,
    first_try_seconds,
    first_try_time_id,
    first_try_day,
    difficulty_tier,
    puzzle_name
FROM counted
WHERE solvers >= :minSolvers
SQL;

        /**
         * @var list<array{
         *     subject_id: string,
         *     puzzle_id: string,
         *     pieces_count: int,
         *     attempts: int,
         *     best_seconds: int,
         *     best_time_id: string,
         *     best_day: string,
         *     first_try_seconds: null|int,
         *     first_try_time_id: null|string,
         *     first_try_day: null|string,
         *     difficulty_tier: null|int,
         *     puzzle_name: null|string,
         * }> $rows
         */
        $rows = $this->database->fetchAllAssociative($query, $parameters, $types);

        $prefix = $isSolo ? ComparisonSubjectRef::player(...) : ComparisonSubjectRef::team(...);
        $refsById = [];

        foreach ($subjectIds as $subjectId) {
            $refsById[$subjectId] = $prefix($subjectId);
        }

        $result = [];

        foreach ($rows as $row) {
            $result[] = new ComparisonTimeRow(
                subject: $refsById[strtolower($row['subject_id'])] ?? $prefix($row['subject_id']),
                puzzleId: $row['puzzle_id'],
                piecesCount: (int) $row['pieces_count'],
                attempts: (int) $row['attempts'],
                bestSeconds: (int) $row['best_seconds'],
                bestTimeId: $row['best_time_id'],
                bestDay: new DateTimeImmutable($row['best_day']),
                firstTrySeconds: $row['first_try_seconds'] !== null ? (int) $row['first_try_seconds'] : null,
                firstTryTimeId: $row['first_try_time_id'],
                firstTryDay: $row['first_try_day'] !== null ? new DateTimeImmutable($row['first_try_day']) : null,
                difficultyTier: $withDifficulty && $row['difficulty_tier'] !== null ? (int) $row['difficulty_tier'] : null,
                puzzleName: $withPuzzleNames ? $row['puzzle_name'] : null,
            );
        }

        return $result;
    }

    /**
     * The tier chips of SearchPuzzle::difficultyFilter(): rated tiers by value, "not rated yet" = no tier computed.
     *
     * @param list<int> $tiers
     * @param array<string, mixed> $parameters
     * @param array<string, ArrayParameterType|ParameterType> $types
     */
    private static function difficultyCondition(array $tiers, array &$parameters, array &$types): string
    {
        if ($tiers === []) {
            return '';
        }

        $ratedTiers = array_values(array_filter(
            $tiers,
            static fn(int $tier): bool => $tier !== ComparisonCriteria::UNRATED_DIFFICULTY,
        ));

        $conditions = [];

        if ($ratedTiers !== []) {
            $conditions[] = 'puzzle_difficulty.difficulty_tier IN (:difficultyTiers)';
            $parameters['difficultyTiers'] = $ratedTiers;
            $types['difficultyTiers'] = ArrayParameterType::INTEGER;
        }

        if (count($ratedTiers) !== count($tiers)) {
            $conditions[] = 'puzzle_difficulty.difficulty_tier IS NULL';
        }

        return ' AND (' . implode(' OR ', $conditions) . ')';
    }
}
