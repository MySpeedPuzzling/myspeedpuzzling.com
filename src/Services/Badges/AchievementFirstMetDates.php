<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Badges;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\AchievementSqlRules;
use SpeedPuzzling\Web\BadgeConditions\BadgeConditionInterface;
use SpeedPuzzling\Web\Query\GetPlayerStatsSnapshot;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * When a player's records first met each achievement tier (docs/features/badges.md "Real dates"): the player's history
 * replayed in date order - their results, their accepted proposals, their approved puzzles - through the badge
 * conditions themselves, so a tier's date is the moment the rule that awards it would have awarded it. The metrics are
 * built step by step with the predicates of GetPlayerStatsSnapshot (participation vs own results, suspicious left
 * out, the same days, quarters and weekend rule), and the replay has to end exactly at the player's current snapshot -
 * otherwise it describes other records than the ones the tiers were awarded for, and no date is trusted (null).
 *
 * The moment of a result is when it was solved (finished_at) - its logging time (tracked_at) when it has no
 * finished_at, or one that is later than the logging (a typo) or before 2000. An accepted proposal counts from its
 * review, an approved puzzle from its approval, or from when it was added where the approval has no date.
 *
 * @phpstan-import-type ReplayEvent from AchievementReplayState
 */
readonly class AchievementFirstMetDates
{
    /**
     * @param iterable<BadgeConditionInterface> $conditions
     */
    public function __construct(
        #[AutowireIterator('badge.condition')]
        private iterable $conditions,
        private Connection $database,
        private GetPlayerStatsSnapshot $getPlayerStatsSnapshot,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return null|array<string, array<int, DateTimeImmutable>> type value → tier value → first met (only tiers the
     *                                                              records meet); null = the replay does not end at
     *                                                              the player's current snapshot
     */
    public function forPlayer(string $playerId): null|array
    {
        $events = [
            ...$this->solveEvents($playerId),
            ...$this->librarianEvents($playerId),
            ...$this->catalogerEvents($playerId),
            ...$this->competitorEvents($playerId),
        ];

        // In time order; an event without a known moment last (a tier it completes gets no date)
        usort($events, static fn (array $a, array $b): int => [$a['at'] === null, $a['at']] <=> [$b['at'] === null, $b['at']]);

        $state = new AchievementReplayState($playerId);
        /** @var array<string, array<int, DateTimeImmutable>> $firstMet */
        $firstMet = [];
        $count = count($events);

        for ($i = 0; $i < $count; $i++) {
            $state->apply($events[$i]);

            // Every event of the same moment first, then ask the rules
            if ($i + 1 < $count && $events[$i + 1]['at'] === $events[$i]['at']) {
                continue;
            }

            $at = $events[$i]['at'];

            if ($at === null) {
                continue;
            }

            $snapshot = $state->snapshot();

            foreach ($this->conditions as $condition) {
                $type = $condition->badgeType()->value;

                foreach ($condition->qualifiedTiers($snapshot) as $tier) {
                    $firstMet[$type][$tier->value] ??= new DateTimeImmutable($at, new DateTimeZone('UTC'));
                }
            }
        }

        if ($state->snapshot() != $this->getPlayerStatsSnapshot->forPlayer($playerId)) {
            return null;
        }

        return $firstMet;
    }

    /**
     * The same rows as GetPlayerStatsSnapshot: the player's results and the pair/team results they were in, never a
     * suspicious one.
     *
     * @return list<ReplayEvent>
     */
    private function solveEvents(string $playerId): array
    {
        $competitionJoins = AchievementSqlRules::RESULT_COMPETITION_JOINS;
        $nightOwl = AchievementSqlRules::IS_NIGHT_OWL_RESULT;
        $onlineCompetition = AchievementSqlRules::IS_ONLINE_COMPETITION_RESULT;

        $sql = <<<SQL
SELECT
    'solve' AS kind,
    pst.player_id = :playerId AS own,
    CAST(pst.puzzle_id AS TEXT) AS puzzle_id,
    p.pieces_count,
    CAST(p.manufacturer_id AS TEXT) AS manufacturer_id,
    pst.seconds_to_solve,
    pst.puzzling_type,
    pst.first_attempt,
    pst.unboxed,
    pst.finished_puzzle_photo IS NOT NULL AS photo,
    TO_CHAR(COALESCE(pst.finished_at, pst.tracked_at), 'YYYY-MM-DD') AS solve_day,
    CAST(EXTRACT(ISODOW FROM COALESCE(pst.finished_at, pst.tracked_at)) AS INTEGER) AS iso_dow,
    COALESCE(pst.finished_at, pst.tracked_at) >= '2000-01-01' AS dated,
    CAST(EXTRACT(YEAR FROM COALESCE(pst.finished_at, pst.tracked_at)) AS INTEGER) AS solve_year,
    CAST(EXTRACT(QUARTER FROM COALESCE(pst.finished_at, pst.tracked_at)) AS INTEGER) AS solve_quarter,
    {$nightOwl} AS night_owl,
    {$onlineCompetition} AS online_competition,
    TO_CHAR(
        CASE
            WHEN pst.finished_at IS NOT NULL AND pst.finished_at <= pst.tracked_at AND pst.finished_at >= '2000-01-01'
                THEN pst.finished_at
            ELSE pst.tracked_at
        END,
        'YYYY-MM-DD HH24:MI:SS.US'
    ) AS at
FROM puzzle_solving_time pst
JOIN puzzle p ON p.id = pst.puzzle_id
LEFT JOIN player owning_player ON owning_player.id = CAST(:playerId AS UUID)
{$competitionJoins}
WHERE pst.suspicious = false
  AND (
    pst.player_id = :playerId
    OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
  )
SQL;

        return array_map(
            static fn (array $row): array => AchievementReplayState::solve($row),
            $this->database->executeQuery($sql, ['playerId' => $playerId])->fetchAllAssociative(),
        );
    }

    /**
     * @return list<ReplayEvent>
     */
    private function librarianEvents(string $playerId): array
    {
        $sql = <<<SQL
SELECT 'librarian' AS kind, TO_CHAR(COALESCE(reviewed_at, submitted_at), 'YYYY-MM-DD HH24:MI:SS.US') AS at
FROM puzzle_change_request
WHERE reporter_id = :playerId AND status = 'approved'
UNION ALL
SELECT 'librarian' AS kind, TO_CHAR(COALESCE(reviewed_at, submitted_at), 'YYYY-MM-DD HH24:MI:SS.US') AS at
FROM puzzle_merge_request
WHERE reporter_id = :playerId AND status = 'approved'
SQL;

        return array_map(
            static fn (array $row): array => AchievementReplayState::other('librarian', $row['at'] ?? null),
            $this->database->executeQuery($sql, ['playerId' => $playerId])->fetchAllAssociative(),
        );
    }

    /**
     * @return list<ReplayEvent>
     */
    private function catalogerEvents(string $playerId): array
    {
        $sql = <<<SQL
SELECT 'cataloger' AS kind, TO_CHAR(COALESCE(approved_at, added_at), 'YYYY-MM-DD HH24:MI:SS.US') AS at
FROM puzzle
WHERE approved = true
  AND added_by_user_id = :playerId
SQL;

        return array_map(
            static fn (array $row): array => AchievementReplayState::other('cataloger', $row['at'] ?? null),
            $this->database->executeQuery($sql, ['playerId' => $playerId])->fetchAllAssociative(),
        );
    }

    /**
     * Competitor: a competition counts from the day after it ended, or from when the player was connected to it or
     * linked a result there if that came later
     *
     * @return list<ReplayEvent>
     */
    private function competitorEvents(string $playerId): array
    {
        return array_map(
            static fn (array $row): array => AchievementReplayState::other(
                'competitor',
                $row['at'] ?? null,
                is_string($row['competition_id'] ?? null) ? $row['competition_id'] : '',
            ),
            $this->database->executeQuery(AchievementSqlRules::COMPETITOR_EVENTS, [
                'playerId' => $playerId,
                'today' => GetPlayerStatsSnapshot::today($this->clock),
            ])->fetchAllAssociative(),
        );
    }
}
