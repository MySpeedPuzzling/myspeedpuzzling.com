<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;

/**
 * The MySpeedPuzzling times a seating proposal orders entrants by ("fastest at table 1",
 * docs/features/competitions-management/seating.md) - set-based, one statement for all people of a round and one for
 * all its pairs/teams, whatever the number of entrants.
 *
 * Only ever used to ORDER entrants: no value leaves the server (the organiser sees the order and how many had no data).
 * A player whose profile is private to the organiser (PrivateProfileAccess) has no data - the event page's
 * participants chart leaves them out as well. Organiser tooling behind COMPETITION_EDIT: blocks do not apply.
 */
readonly final class GetSeatingSeedTimes
{
    // Own times older than this are not a player's speed any more (the fallback when there is no baseline)
    public const int RECENT_MONTHS = 24;

    // No puzzle in the round yet: the piece count the event page's participants chart compares people by
    public const int DEFAULT_PIECES_COUNT = 500;

    public function __construct(
        private Connection $database,
        private PrivateProfileAccess $privateProfileAccess,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The piece count of the round's puzzle(s): the only one, else the most frequent (then the largest); null when the
     * round has no puzzle yet.
     */
    public function roundPiecesCount(string $roundId): null|int
    {
        $piecesCount = $this->database->fetchOne(
            <<<SQL
SELECT puzzle.pieces_count
FROM competition_round_puzzle crp
INNER JOIN puzzle ON puzzle.id = crp.puzzle_id
WHERE crp.round_id = :roundId
GROUP BY puzzle.pieces_count
ORDER BY COUNT(*) DESC, puzzle.pieces_count DESC
LIMIT 1
SQL,
            ['roundId' => $roundId],
        );

        return is_numeric($piecesCount) ? (int) $piecesCount : null;
    }

    /**
     * Each player's expected solo time for the piece count: their Puzzle Insights baseline (PlayerBaseline - the
     * weighted median of first-attempt solo times, interpolated from their other piece counts when needed), else the
     * median of their own solo times of that piece count from the last RECENT_MONTHS.
     *
     * @param array<string> $playerIds
     * @return array<string, null|int> player id (lower case) => seconds, null without data; players private to the
     *         viewer are left out altogether (their times must not order anything)
     */
    public function forPlayers(array $playerIds, int $piecesCount): array
    {
        $playerIds = array_values(array_unique(array_map(strtolower(...), $playerIds)));

        if ($playerIds === []) {
            return [];
        }

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT
    p.id AS player_id,
    {$this->privateProfileAccess->sqlIsPrivate('p')} AS is_private,
    pb.baseline_seconds,
    recent.median_seconds
FROM player p
LEFT JOIN player_baseline pb ON pb.player_id = p.id AND pb.pieces_count = :piecesCount
LEFT JOIN LATERAL (
    SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY pst.seconds_to_solve) AS median_seconds
    FROM puzzle_solving_time pst
    INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
    WHERE pb.id IS NULL
        AND pst.player_id = p.id
        AND pst.puzzling_type = 'solo'
        AND pst.seconds_to_solve IS NOT NULL
        AND pst.suspicious = false
        AND puzzle.pieces_count = :piecesCount
        AND COALESCE(pst.finished_at, pst.tracked_at) >= :since
) recent ON true
WHERE p.id IN (:playerIds)
SQL,
            [
                'playerIds' => $playerIds,
                'piecesCount' => $piecesCount,
                'since' => $this->since(),
            ],
            [
                'playerIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        $seconds = [];

        foreach ($rows as $row) {
            /** @var array{player_id: string, is_private: bool, baseline_seconds: null|int, median_seconds: null|float|string} $row */
            if ($row['is_private'] === true) {
                continue;
            }

            $seconds[$row['player_id']] = match (true) {
                $row['baseline_seconds'] !== null => $row['baseline_seconds'],
                is_numeric($row['median_seconds']) => (int) round((float) $row['median_seconds']),
                default => null,
            };
        }

        return $seconds;
    }

    /**
     * The median time of the puzzling team with exactly these people (puzzling_team.composition_key, TeamComposition)
     * for the piece count, from the last RECENT_MONTHS. The caller passes only keys of teams whose every member is a
     * player visible to the organiser.
     *
     * @param array<string> $compositionKeys
     * @return array<string, int> composition key => seconds; teams without a time of that piece count are left out
     */
    public function forTeams(array $compositionKeys, int $piecesCount): array
    {
        $compositionKeys = array_values(array_unique($compositionKeys));

        if ($compositionKeys === []) {
            return [];
        }

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT
    pt.composition_key,
    percentile_cont(0.5) WITHIN GROUP (ORDER BY pst.seconds_to_solve) AS median_seconds
FROM puzzling_team pt
INNER JOIN puzzle_solving_time pst ON pst.puzzling_team_id = pt.id
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
WHERE pt.composition_key IN (:keys)
    AND pst.seconds_to_solve IS NOT NULL
    AND pst.suspicious = false
    AND puzzle.pieces_count = :piecesCount
    AND COALESCE(pst.finished_at, pst.tracked_at) >= :since
GROUP BY pt.composition_key
SQL,
            [
                'keys' => $compositionKeys,
                'piecesCount' => $piecesCount,
                'since' => $this->since(),
            ],
            [
                'keys' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        $seconds = [];

        foreach ($rows as $row) {
            /** @var array{composition_key: string, median_seconds: null|float|string} $row */
            if (is_numeric($row['median_seconds'])) {
                $seconds[trim($row['composition_key'])] = (int) round((float) $row['median_seconds']);
            }
        }

        return $seconds;
    }

    private function since(): string
    {
        return $this->clock->now()->modify(sprintf('-%d months', self::RECENT_MONTHS))->format('Y-m-d H:i:s');
    }
}
