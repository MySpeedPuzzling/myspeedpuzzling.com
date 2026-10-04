<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\CompetitionPuzzle;
use SpeedPuzzling\Web\Results\PuzzleOverview;

/**
 * The puzzles used at competitions - the ones carrying the competition's tag, the ones attached to its
 * rounds, or the ones people logged times for there. A secret puzzle never leaks - the same embargo
 * rules as GetEditionRounds:
 *
 * - puzzle.hide_until drops the puzzle, puzzle.hide_image_until drops its image;
 * - a round puzzle flagged hide-until-round-starts stays hidden (mode "entirely") or imageless
 *   (mode "image only") until 10 minutes after its round starts - even when the tag lists it too.
 */
readonly final class GetCompetitionPuzzles
{
    /**
     * Per puzzle of one competition's (:competitionId) rounds: its first round's start and whether a
     * round still hides it entirely or just its image (hide-until-round-starts, revealed 10 minutes after
     * the round starts, the same rule as GetEditionRounds).
     */
    private const string ROUND_PUZZLE_RULES = <<<SQL
    SELECT
        crp.puzzle_id,
        MIN(cr.starts_at) AS first_round_starts_at,
        BOOL_OR(
            crp.hide_until_round_starts
            AND cr.starts_at + INTERVAL '10 minutes' > :now::timestamp
            AND COALESCE(crp.hide_mode, 'entirely') = 'entirely'
        ) AS hidden_entirely,
        BOOL_OR(
            crp.hide_until_round_starts
            AND cr.starts_at + INTERVAL '10 minutes' > :now::timestamp
        ) AS image_hidden
    FROM competition_round cr
    INNER JOIN competition_round_puzzle crp ON crp.round_id = cr.id
    WHERE cr.competition_id = :competitionId
    GROUP BY crp.puzzle_id
SQL;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Tagged and round puzzles of several competitions at once, with their public statistics (the WJPC hub).
     * Only publicly visible competitions (IsCompetitionPubliclyVisible) answer.
     * Order per competition: round puzzles in schedule order, then tag-only puzzles, most solved first.
     *
     * @param array<string> $competitionIds
     * @return array<string, list<CompetitionPuzzle>> keyed by competition id, competitions without puzzles left out
     */
    public function forCompetitions(array $competitionIds): array
    {
        $competitionIds = array_values(array_filter(
            $competitionIds,
            static fn (string $competitionId): bool => Uuid::isValid($competitionId),
        ));

        if ($competitionIds === []) {
            return [];
        }

        $visibility = IsCompetitionPubliclyVisible::SQL_CONDITION;

        $query = <<<SQL
WITH competition_puzzle AS (
    SELECT
        c.id AS competition_id,
        tp.puzzle_id,
        NULL::timestamp AS round_starts_at,
        false AS hidden_entirely,
        false AS image_hidden
    FROM competition c
    LEFT JOIN competition_series cs ON cs.id = c.series_id
    INNER JOIN tag_puzzle tp ON tp.tag_id = c.tag_id
    WHERE c.id IN (:competitionIds)
        AND {$visibility}

    UNION ALL

    SELECT
        c.id AS competition_id,
        crp.puzzle_id,
        cr.starts_at AS round_starts_at,
        crp.hide_until_round_starts
            AND cr.starts_at + INTERVAL '10 minutes' > :now::timestamp
            AND COALESCE(crp.hide_mode, 'entirely') = 'entirely' AS hidden_entirely,
        crp.hide_until_round_starts
            AND cr.starts_at + INTERVAL '10 minutes' > :now::timestamp AS image_hidden
    FROM competition c
    LEFT JOIN competition_series cs ON cs.id = c.series_id
    INNER JOIN competition_round cr ON cr.competition_id = c.id
    INNER JOIN competition_round_puzzle crp ON crp.round_id = cr.id
    WHERE c.id IN (:competitionIds)
        AND {$visibility}
),
competition_puzzle_rules AS (
    SELECT
        competition_id,
        puzzle_id,
        MIN(round_starts_at) AS first_round_starts_at,
        BOOL_OR(hidden_entirely) AS hidden_entirely,
        BOOL_OR(image_hidden) AS image_hidden
    FROM competition_puzzle
    GROUP BY competition_id, puzzle_id
)
SELECT
    rules.competition_id,
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.pieces_count,
    m.name AS manufacturer_name,
    CASE
        WHEN rules.image_hidden OR (p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp)
        THEN NULL
        ELSE p.image
    END AS puzzle_image,
    CASE
        WHEN rules.image_hidden OR (p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp)
        THEN NULL
        ELSE p.image_ratio
    END AS puzzle_image_ratio,
    COALESCE(ps.solved_times_solo_count, 0) AS solo_solves_count,
    ps.median_time_solo,
    ps.fastest_time_solo,
    ps.fastest_time_duo,
    ps.fastest_time_team
FROM competition_puzzle_rules rules
INNER JOIN puzzle p ON p.id = rules.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = p.id
WHERE rules.hidden_entirely = false
    AND (p.hide_until IS NULL OR p.hide_until <= :now::timestamp)
ORDER BY rules.competition_id, rules.first_round_starts_at ASC NULLS LAST, COALESCE(ps.solved_times_count, 0) DESC, p.name
SQL;

        $rows = $this->database
            ->executeQuery(
                $query,
                [
                    'competitionIds' => $competitionIds,
                    'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                ],
                ['competitionIds' => ArrayParameterType::STRING],
            )
            ->fetchAllAssociative();

        $puzzles = [];

        foreach ($rows as $row) {
            /**
             * @var array{
             *     competition_id: string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     pieces_count: int|string,
             *     manufacturer_name: null|string,
             *     puzzle_image: null|string,
             *     puzzle_image_ratio: null|float|string,
             *     solo_solves_count: int|string,
             *     median_time_solo: null|int|string,
             *     fastest_time_solo: null|int|string,
             *     fastest_time_duo: null|int|string,
             *     fastest_time_team: null|int|string,
             * } $row
             */
            $puzzles[$row['competition_id']][] = new CompetitionPuzzle(
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                piecesCount: (int) $row['pieces_count'],
                manufacturerName: $row['manufacturer_name'],
                puzzleImage: $row['puzzle_image'],
                puzzleImageRatio: $row['puzzle_image_ratio'] !== null ? (float) $row['puzzle_image_ratio'] : null,
                soloSolvesCount: (int) $row['solo_solves_count'],
                medianTimeSolo: $row['median_time_solo'] !== null ? (int) $row['median_time_solo'] : null,
                fastestTimeSolo: $row['fastest_time_solo'] !== null ? (int) $row['fastest_time_solo'] : null,
                fastestTimeDuo: $row['fastest_time_duo'] !== null ? (int) $row['fastest_time_duo'] : null,
                fastestTimeTeam: $row['fastest_time_team'] !== null ? (int) $row['fastest_time_team'] : null,
            );
        }

        return $puzzles;
    }

    /**
     * The puzzles of one competition's rounds, each once, in schedule order - for an event page whose
     * event has no tagged puzzles.
     *
     * @return list<PuzzleOverview>
     */
    public function roundPuzzleOverviews(string $competitionId): array
    {
        if (Uuid::isValid($competitionId) === false) {
            return [];
        }

        $columns = self::puzzleOverviewColumns('round_puzzle.image_hidden');
        $roundPuzzleRules = self::ROUND_PUZZLE_RULES;

        $query = <<<SQL
WITH round_puzzle AS (
{$roundPuzzleRules}
)
SELECT
{$columns}
FROM round_puzzle
INNER JOIN puzzle ON puzzle.id = round_puzzle.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN puzzle_statistics ON puzzle_statistics.puzzle_id = puzzle.id
WHERE round_puzzle.hidden_entirely = false
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
ORDER BY round_puzzle.first_round_starts_at, puzzle.name
SQL;

        return $this->puzzleOverviews($query, ['competitionId' => $competitionId], []);
    }

    /**
     * The puzzles people logged (not suspicious) times for at a competition, the most logged first -
     * for an event page whose event has neither tagged nor visible round puzzles, e.g. championships
     * entered without rounds. Capped, because a perpetual online event collects hundreds of puzzles.
     * A round's secret puzzle stays secret here too, even when someone already linked a time to it.
     *
     * @return list<PuzzleOverview>
     */
    public function solvedPuzzleOverviews(string $competitionId, int $limit): array
    {
        if (Uuid::isValid($competitionId) === false) {
            return [];
        }

        $columns = self::puzzleOverviewColumns('COALESCE(round_puzzle.image_hidden, false)');
        $roundPuzzleRules = self::ROUND_PUZZLE_RULES;

        $query = <<<SQL
WITH solved_puzzle AS (
    SELECT pst.puzzle_id, COUNT(*) AS times_count
    FROM puzzle_solving_time pst
    WHERE pst.competition_id = :competitionId
        AND pst.suspicious = false
    GROUP BY pst.puzzle_id
),
round_puzzle AS (
{$roundPuzzleRules}
)
SELECT
{$columns}
FROM solved_puzzle
INNER JOIN puzzle ON puzzle.id = solved_puzzle.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN puzzle_statistics ON puzzle_statistics.puzzle_id = puzzle.id
LEFT JOIN round_puzzle ON round_puzzle.puzzle_id = puzzle.id
WHERE (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
    AND round_puzzle.hidden_entirely IS NOT TRUE
ORDER BY solved_puzzle.times_count DESC, puzzle.name
LIMIT :limit
SQL;

        return $this->puzzleOverviews(
            $query,
            ['competitionId' => $competitionId, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );
    }

    /**
     * The columns PuzzleOverview::fromDatabaseRow() reads, from `puzzle` joined with manufacturer and
     * puzzle_statistics; $imageHiddenExpression is the caller's extra rule that drops the image.
     */
    private static function puzzleOverviewColumns(string $imageHiddenExpression): string
    {
        $imageHidden = "{$imageHiddenExpression} OR (puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp)";

        return <<<SQL
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    CASE WHEN {$imageHidden} THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN {$imageHidden} THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
    puzzle.hide_image_until,
    puzzle.hide_until,
    puzzle.alternative_names AS puzzle_alternative_names,
    puzzle.pieces_count,
    puzzle.is_available,
    puzzle.approved AS puzzle_approved,
    manufacturer.id AS manufacturer_id,
    manufacturer.name AS manufacturer_name,
    manufacturer.slug AS manufacturer_slug,
    puzzle.ean AS puzzle_ean,
    puzzle.identification_number AS puzzle_identification_number,
    COALESCE(puzzle_statistics.solved_times_count, 0) AS solved_times,
    puzzle_statistics.average_time_solo,
    puzzle_statistics.fastest_time_solo,
    puzzle_statistics.average_time_duo,
    puzzle_statistics.fastest_time_duo,
    puzzle_statistics.average_time_team,
    puzzle_statistics.fastest_time_team
SQL;
    }

    /**
     * @param array<string, int|string> $parameters
     * @param array<string, ParameterType> $types
     * @return list<PuzzleOverview>
     */
    private function puzzleOverviews(string $query, array $parameters, array $types): array
    {
        $rows = $this->database
            ->executeQuery($query, $parameters + ['now' => $this->clock->now()->format('Y-m-d H:i:s')], $types)
            ->fetchAllAssociative();

        return array_map(static function (array $row): PuzzleOverview {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_image: null|string,
             *     puzzle_image_ratio: null|string,
             *     puzzle_alternative_names: string,
             *     puzzle_approved: bool,
             *     manufacturer_id: string,
             *     manufacturer_name: string,
             *     manufacturer_slug: null|string,
             *     pieces_count: int,
             *     average_time_solo: null|string,
             *     fastest_time_solo: null|int,
             *     average_time_duo: null|string,
             *     fastest_time_duo: null|int,
             *     average_time_team: null|string,
             *     fastest_time_team: null|int,
             *     solved_times: int,
             *     is_available: bool,
             *     puzzle_ean: null|string,
             *     puzzle_identification_number: null|string,
             *     hide_image_until: null|string,
             *     hide_until: null|string,
             * } $row
             */
            return PuzzleOverview::fromDatabaseRow($row);
        }, $rows);
    }
}
