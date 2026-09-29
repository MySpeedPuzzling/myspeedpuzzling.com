<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\CompetitionPuzzle;

/**
 * The puzzles used at competitions - the ones carrying the competition's tag and the ones attached to
 * its rounds. A secret puzzle never leaks - the same embargo rules as GetEditionRounds:
 *
 * - puzzle.hide_until drops the puzzle, puzzle.hide_image_until drops its image;
 * - a round puzzle flagged hide-until-round-starts stays hidden (mode "entirely") or imageless
 *   (mode "image only") until 10 minutes after its round starts - even when the tag lists it too.
 */
readonly final class GetCompetitionPuzzles
{
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
}
