<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\CollectionDifficultyRank;
use SpeedPuzzling\Web\Results\PersonalEstimate;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\RepeatResiduals;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\DifficultySource;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\EstimateBasis;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\ImagePriorInput;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Value\StatisticalPredictionInputs;

/**
 * The personal time estimates of one player for a list of puzzles in ONE statement, however long the list
 * (docs/features/insights/estimates.md): the player's solo attempts on the listed puzzles, their ratios, the spread of
 * their stored repeat predictions and the fitted model parameters (one row, evaluated once), the global ratios of the
 * listed piece counts, the statistical inputs and the picture estimate of every listed puzzle, and - only for a player
 * without any pace - the community's median pace per piece count. The math is TimePredictionCalculator's, fed the same
 * inputs as GetPlayerPrediction::forPuzzle(), so a puzzle shows the same time here as on every other surface (pinned by
 * GetPersonalEstimatesTest).
 *
 * Which time (the one rule, estimates.md "Which number"):
 * - solved before (solo, valid, boxed) → the personal prediction (`your_solves`);
 * - else the statistical prediction where it exists: 5+ indices `measured`, 1-4 `first_solves` (shrunk toward brand ×
 *   pieces - the image prior reaches shown predictions only with the launch MODEL_VERSION bump);
 * - else, at k = 0 only, the picture-only prediction (pace × the estimate of the current picture) where $pictureShown
 *   (ImageEstimateGate) says the size group × image kind passed - the `image` side row's number;
 * - else, for a player without any baseline, the community's median pace at the piece count (`community`);
 * - nothing for a secret puzzle (hide_until / hide_image_until ahead), whatever it has.
 */
readonly final class GetPersonalEstimates
{
    /** A community median rests on at least this many puzzlers' paces */
    public const int COMMUNITY_MIN_PLAYERS = 20;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private TimePredictionCalculator $calculator,
    ) {
    }

    /**
     * @param array<string> $puzzleIds duplicates are fine
     * @param callable(PiecesGroup, ImageKind): bool $pictureShown whether picture-only estimates show for the group
     *
     * @return array<string, PersonalEstimate> keyed by puzzle id; a puzzle without anything to show is missing
     */
    public function forPuzzles(string $playerId, array $puzzleIds, callable $pictureShown): array
    {
        $puzzleIds = array_values(array_unique($puzzleIds));

        if ($puzzleIds === []) {
            return [];
        }

        $rows = $this->rows($playerId, $puzzleIds);

        if ($rows === []) {
            return [];
        }

        $first = $rows[0];

        foreach ($rows as $row) {
            if (($row['carries_player'] ?? null) === true) {
                $first = $row;

                break;
            }
        }

        $parameters = PredictionModelParametersProvider::fromJson(is_string($first['model_parameters'] ?? null) ? $first['model_parameters'] : null);
        $playerRatios = self::playerRatios($first['ratios'] ?? null);
        $repeatSpread = TimePredictionCalculator::playerSpread(RepeatResiduals::fromJson(is_string($first['repeats'] ?? null) ? $first['repeats'] : null));
        $globalRatios = self::globalRatios($first['global_ratios'] ?? null);
        $community = self::community($first['community'] ?? null);
        // Unknown (no row carried the player's part) = no community time: a player with a pace never gets one
        $hasBaseline = ($first['has_baseline'] ?? null) !== false;
        $now = $this->clock->now();
        $estimates = [];

        foreach ($rows as $row) {
            if (($row['secret'] ?? true) !== false || !is_string($row['puzzle_id'] ?? null)) {
                continue;
            }

            $puzzleId = $row['puzzle_id'];
            $inputs = StatisticalInputsSql::hydrate($row);
            $imageEstimate = ImagePriorInput::fromRow($row);
            $k = $inputs->kIndices;
            $group = PiecesGroup::fromPiecesCount($inputs->piecesCount);
            // Asked once per puzzle at most, and only for a puzzle nobody has measured that has a picture estimate
            $pictureOpen = $k === 0 && $imageEstimate !== null && $pictureShown($group, $imageEstimate->kind);
            $pictureEstimate = $pictureOpen ? $imageEstimate : null;
            [$tier, $tierEstimated, $difficulty] = $this->tierOf($row, $inputs, $pictureEstimate, $parameters);

            $times = self::times($row['times'] ?? null);
            $prediction = null;
            $basis = null;

            if ($times !== [] && is_string($row['last_solved_at'] ?? null)) {
                $prediction = $this->personal($times, new DateTimeImmutable($row['last_solved_at']), $inputs->piecesCount, $now, $playerRatios, $globalRatios, $repeatSpread, $parameters);
                $basis = EstimateBasis::YourSolves;
            } else {
                $prediction = $this->calculator->statistical($inputs, $parameters);

                if ($prediction !== null) {
                    $basis = $prediction->difficultySource === DifficultySource::Solves ? EstimateBasis::Measured : EstimateBasis::FirstSolves;
                } elseif ($pictureEstimate !== null) {
                    $prediction = $this->calculator->statistical($inputs->withImageEstimate($pictureEstimate)->withoutSolves(), $parameters, allowPriorOnly: true);
                    $basis = $prediction !== null ? EstimateBasis::Picture : null;
                }
            }

            if ($prediction !== null && $basis !== null) {
                $estimates[$puzzleId] = new PersonalEstimate($puzzleId, $inputs->piecesCount, $basis, $prediction, $k, $tier, $tierEstimated, difficulty: $difficulty);

                continue;
            }

            $median = $hasBaseline === false ? ($community[$inputs->piecesCount] ?? null) : null;

            if ($median !== null) {
                $estimates[$puzzleId] = new PersonalEstimate(
                    $puzzleId,
                    $inputs->piecesCount,
                    EstimateBasis::Community,
                    null,
                    $k,
                    $tier,
                    $tierEstimated,
                    difficulty: $difficulty,
                    communitySeconds: $median['seconds'],
                    communityPlayers: $median['players'],
                );
            }
        }

        return $estimates;
    }

    /**
     * Where the puzzle stands among the player's own collection puzzles of the same piece count with a measured
     * difficulty (any of their collections, the system one included; never secret ones) - "the 2nd-hardest 1000 in
     * your collection". One short statement, the puzzle page only.
     */
    public function collectionRank(string $playerId, string $puzzleId, int $piecesCount, float $difficulty): CollectionDifficultyRank
    {
        $notSecret = StatisticalInputsSql::NOT_SECRET;
        $rated = TimePredictionCalculator::RATED_INDICES;

        /** @var array{harder: int|string, easier: int|string, rated: int|string, in_collection: bool} $row */
        $row = $this->database->fetchAssociative("
            SELECT
                count(*) FILTER (WHERE pd.difficulty_score > :difficulty) AS harder,
                count(*) FILTER (WHERE pd.difficulty_score < :difficulty) AS easier,
                count(*) AS rated,
                EXISTS (SELECT 1 FROM collection_item own WHERE own.player_id = :playerId AND own.puzzle_id = :puzzleId) AS in_collection
            FROM (SELECT DISTINCT ci.puzzle_id FROM collection_item ci WHERE ci.player_id = :playerId) mine
            JOIN puzzle p ON p.id = mine.puzzle_id
            JOIN puzzle_difficulty pd ON pd.puzzle_id = p.id
            WHERE p.pieces_count = :piecesCount
                AND p.id <> :puzzleId
                AND pd.difficulty_score IS NOT NULL
                AND pd.sample_size >= {$rated}
                AND {$notSecret}
        ", [
            'playerId' => $playerId,
            'puzzleId' => $puzzleId,
            'piecesCount' => $piecesCount,
            'difficulty' => $difficulty,
        ]);

        return new CollectionDifficultyRank(
            inCollection: $row['in_collection'],
            harder: (int) $row['harder'],
            easier: (int) $row['easier'],
            others: (int) $row['rated'],
        );
    }

    /**
     * The statement (expects :playerId, :puzzleIds as a uuid[] literal and :modelVersion) - public for EXPLAIN on a
     * production copy (docs/features/insights/estimates.md "Measured").
     */
    public static function statement(): string
    {
        $cte = StatisticalInputsSql::ownPaceCte();
        $columns = StatisticalInputsSql::COLUMNS . ', ' . StatisticalInputsSql::IMAGE_ESTIMATE_COLUMNS;
        $joins = StatisticalInputsSql::JOINS . StatisticalInputsSql::ownPaceCteJoins() . StatisticalInputsSql::imageEstimateJoins(outOfFoldOnly: false);
        $notSecret = StatisticalInputsSql::NOT_SECRET;
        $repeats = RepeatResiduals::sql(excludeTime: false, before: false);
        $parameters = PredictionModelParametersProvider::SQL;
        $minPlayers = self::COMMUNITY_MIN_PLAYERS;

        // The player's part (`player`) and the community medians are uncorrelated - evaluated once for the statement;
        // the community scan runs only for a player without any baseline (a one-time filter). The listed ids drive the
        // statement (FROM listed JOIN puzzle): a semi join at the end let the planner join every puzzle of the
        // catalogue first (83 ms for 21 puzzles on the production copy). The player's part rides on one row only - on
        // every row its ~6 KB of model parameters made fetching a 1,350-puzzle list cost 55 ms more than running it
        return <<<SQL
WITH {$cte},
listed AS (
    SELECT DISTINCT unnest(CAST(:puzzleIds AS uuid[])) AS id
),
attempts AS (
    SELECT
        pst.puzzle_id,
        array_to_json(array_agg(pst.seconds_to_solve ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at)) AS times,
        max(COALESCE(pst.finished_at, pst.tracked_at)) AS last_solved_at
    FROM puzzle_solving_time pst
    WHERE pst.player_id = :playerId
        AND pst.puzzle_id IN (SELECT id FROM listed)
        AND pst.puzzling_type = 'solo'
        AND pst.suspicious = false
        AND pst.seconds_to_solve IS NOT NULL
        AND pst.unboxed = false
    GROUP BY pst.puzzle_id
),
listed_pieces AS (
    SELECT DISTINCT lp.pieces_count FROM puzzle lp WHERE lp.id IN (SELECT id FROM listed)
),
player AS (
    SELECT
        true AS carries_player,
        EXISTS (SELECT 1 FROM player_baseline mine WHERE mine.player_id = :playerId) AS has_baseline,
        (SELECT json_object_agg(r.from_attempt, r.median_ratio) FROM player_improvement_ratio r WHERE r.player_id = :playerId) AS ratios,
        ({$repeats}) AS repeats,
        ({$parameters}) AS model_parameters,
        (
            SELECT json_agg(json_build_array(g.pieces_count, g.from_attempt, g.gap_bucket, g.median_ratio))
            FROM global_improvement_ratio g
            WHERE g.pieces_count IN (SELECT pieces_count FROM listed_pieces)
        ) AS global_ratios,
        (
            SELECT json_object_agg(c.pieces_count, json_build_array(c.median, c.players))
            FROM (
                SELECT b.pieces_count, percentile_cont(0.5) WITHIN GROUP (ORDER BY b.baseline_seconds) AS median, count(*) AS players
                FROM player_baseline b
                WHERE b.baseline_type = 'direct'
                    AND b.baseline_seconds > 0
                    AND b.pieces_count IN (SELECT pieces_count FROM listed_pieces)
                    AND NOT EXISTS (SELECT 1 FROM player_baseline mine WHERE mine.player_id = :playerId)
                GROUP BY b.pieces_count
                HAVING count(*) >= {$minPlayers}
            ) c
        ) AS community
)
SELECT
    {$columns},
    NOT ({$notSecret}) AS secret,
    pd.difficulty_tier,
    att.times,
    att.last_solved_at,
    player.carries_player,
    player.has_baseline,
    player.ratios,
    player.repeats,
    player.model_parameters,
    player.global_ratios,
    player.community
FROM listed
JOIN puzzle p ON p.id = listed.id
{$joins}
LEFT JOIN attempts att ON att.puzzle_id = p.id
LEFT JOIN player ON p.id = (SELECT first.id FROM listed first JOIN puzzle known ON known.id = first.id ORDER BY first.id LIMIT 1)
SQL;
    }

    /**
     * @param non-empty-list<string> $puzzleIds
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $playerId, array $puzzleIds): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database->executeQuery(self::statement(), [
            'playerId' => $playerId,
            'puzzleIds' => '{' . implode(',', $puzzleIds) . '}',
            'modelVersion' => TimePredictionCalculator::MODEL_VERSION,
        ])->fetchAllAssociative();

        return $rows;
    }

    /**
     * The puzzle's tier as the estimate shows it - the puzzle's own, never personal: measured from 5 indices on; at
     * 1-4 the shrunk difficulty (toward brand × pieces, like the shown prediction); at 0 the picture's ($pictureEstimate:
     * only where it shows). The score it came from rides along (the puzzle page's collection rank).
     *
     * @param array<string, mixed> $row
     *
     * @return array{0: null|DifficultyTier, 1: bool, 2: null|float} tier, estimated, difficulty score
     */
    private function tierOf(array $row, StatisticalPredictionInputs $inputs, null|ImagePriorInput $pictureEstimate, PredictionModelParameters $parameters): array
    {
        $k = $inputs->kIndices;

        if ($k >= TimePredictionCalculator::RATED_INDICES) {
            $stored = $row['difficulty_tier'] ?? null;
            $tier = is_int($stored) || is_string($stored) && is_numeric($stored) ? DifficultyTier::tryFrom((int) $stored) : null;

            return $tier !== null && $inputs->difficultyScore !== null ? [$tier, false, $inputs->difficultyScore] : [null, false, null];
        }

        if ($k >= 1) {
            $difficulty = TimePredictionCalculator::logDifficulty($k, $inputs->sumLogIndices, $inputs->difficultyScore, $this->calculator->prior($inputs->prior)?->logDifficulty, $parameters->tau);

            if ($difficulty === null) {
                return [null, false, null];
            }

            $score = exp($difficulty[0]);

            return [DifficultyTier::fromScore($score), true, $score];
        }

        if ($pictureEstimate !== null) {
            $score = exp($pictureEstimate->logDifficulty);

            return [DifficultyTier::fromScore($score), true, $score];
        }

        return [null, false, null];
    }

    /**
     * GetPlayerPrediction::personalPrediction()'s math on this statement's inputs
     *
     * @param non-empty-list<int> $times
     * @param array<int, float> $playerRatios
     * @param array<int, array<int, array<string, float>>> $globalRatios
     */
    private function personal(
        array $times,
        DateTimeImmutable $lastSolvedAt,
        int $piecesCount,
        DateTimeImmutable $now,
        array $playerRatios,
        array $globalRatios,
        float $repeatSpread,
        PredictionModelParameters $parameters,
    ): TimePredictionResult {
        $transition = TimePredictionCalculator::transitionFor(count($times));
        $gapBucket = TimePredictionCalculator::classifyGap(TimePredictionCalculator::gapDays($now, $lastSolvedAt));
        $playerRatio = $playerRatios[$transition] ?? null;

        return $this->calculator->personal(
            $times,
            TimePredictionCalculator::resolveRatio(
                $playerRatio,
                $globalRatios[$piecesCount][$transition][$gapBucket] ?? null,
                $playerRatio !== null ? ($globalRatios[$piecesCount][$transition]['all'] ?? null) : null,
            ),
            $repeatSpread,
            $piecesCount,
            $parameters,
        );
    }

    /**
     * @return list<int>
     */
    private static function times(mixed $json): array
    {
        if (!is_string($json)) {
            return [];
        }

        $times = [];

        foreach ((array) json_decode($json, true, flags: JSON_THROW_ON_ERROR) as $seconds) {
            if (is_int($seconds)) {
                $times[] = $seconds;
            }
        }

        return $times;
    }

    /**
     * @return array<int, float> transition → median ratio
     */
    private static function playerRatios(mixed $json): array
    {
        $ratios = [];

        foreach (is_string($json) ? (array) json_decode($json, true, flags: JSON_THROW_ON_ERROR) : [] as $fromAttempt => $ratio) {
            if (is_numeric($ratio)) {
                $ratios[(int) $fromAttempt] = (float) $ratio;
            }
        }

        return $ratios;
    }

    /**
     * @return array<int, array<int, array<string, float>>> pieces count → transition → gap bucket → median ratio
     */
    private static function globalRatios(mixed $json): array
    {
        $ratios = [];

        foreach (is_string($json) ? (array) json_decode($json, true, flags: JSON_THROW_ON_ERROR) : [] as $entry) {
            if (is_array($entry) && is_int($entry[0] ?? null) && is_int($entry[1] ?? null) && is_string($entry[2] ?? null) && is_numeric($entry[3] ?? null)) {
                $ratios[$entry[0]][$entry[1]][$entry[2]] = (float) $entry[3];
            }
        }

        return $ratios;
    }

    /**
     * @return array<int, array{seconds: int, players: int}> pieces count → median pace and players
     */
    private static function community(mixed $json): array
    {
        $medians = [];

        foreach (is_string($json) ? (array) json_decode($json, true, flags: JSON_THROW_ON_ERROR) : [] as $piecesCount => $entry) {
            if (is_array($entry) && is_numeric($entry[0] ?? null) && is_int($entry[1] ?? null)) {
                $medians[(int) $piecesCount] = ['seconds' => (int) round((float) $entry[0]), 'players' => $entry[1]];
            }
        }

        return $medians;
    }
}
