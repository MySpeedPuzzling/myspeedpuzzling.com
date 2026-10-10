<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Digest;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\BadgeConditions\BadgeConditionInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Query\GetPlayerRatingRanking;
use SpeedPuzzling\Web\Query\GetPlayerStatsSnapshot;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Results\BadgeProgress;
use SpeedPuzzling\Web\Results\BadgeResult;
use SpeedPuzzling\Web\Results\PeriodRatingChange;
use SpeedPuzzling\Web\Results\PeriodResult;
use SpeedPuzzling\Web\Results\PlayerPeriodActivity;
use SpeedPuzzling\Web\Services\ActivityCalendarStreakCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\DigestPeriod;
use SpeedPuzzling\Web\Value\LevelTable;
use SpeedPuzzling\Web\Value\SkillTier;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * One player's activity in one digest period, for the activity digest e-mail (rendered in a consumer - no viewer)
 * and the owner's "Your week" page (docs/features/content-digest/README.md §"Content").
 *
 * A fixed number of statements whatever the period holds: the player row with the XP sums, the results (with each
 * one's XP and personal-best mark through LATERALs), the achievements, the rating snapshots + the ladder place, the
 * streak days, the favourites, the community's most solved, the next achievement tiers (members) and - on the page -
 * the places on the puzzles' leaderboards (one GetRanking statement for all of them).
 *
 * The period holds what was *solved* in it (COALESCE(finished_at, tracked_at), Europe/Prague period bounds in UTC),
 * like XP (`xp_entry.earned_at` of a solve = its solve moment), streaks and the profile calendar.
 */
readonly final class PlayerPeriodActivityReader
{
    private const int RATING_PIECES_COUNT = PuzzleIntelligenceRecalculator::RATING_PIECES_COUNTS[0];

    /**
     * @param iterable<BadgeConditionInterface> $conditions
     */
    public function __construct(
        private Connection $database,
        #[AutowireIterator('badge.condition')]
        private iterable $conditions,
        private GetBadges $getBadges,
        private GetPlayerStatsSnapshot $getPlayerStatsSnapshot,
        private GetPlayerRatingRanking $getPlayerRatingRanking,
        private GetRanking $getRanking,
        private ActivityCalendarStreakCalculator $streakCalculator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function read(string $playerId, DigestPeriod $period, bool $isMember, bool $withPlaces = false): PlayerPeriodActivity
    {
        $player = $this->player($playerId, $period);
        $results = $this->results($playerId, $period);

        if ($withPlaces && $results !== []) {
            $results = $this->withPlaces($playerId, $results);
        }

        $counted = array_filter($results, static fn (PeriodResult $result): bool => $result->awaitingVerification === false);
        $xpVisible = $player['experience_system_opted_out'] === false;
        $xpAtEnd = max(0, $player['xp_total'] - $player['xp_after']);
        $achievements = $this->achievementsEarned($playerId, $period);

        return new PlayerPeriodActivity(
            period: $period,
            results: $results,
            puzzlesCount: count($counted),
            piecesCount: array_sum(array_map(static fn (PeriodResult $result): int => $result->piecesCount, $counted)),
            minutesSpent: intdiv(array_sum(array_map(static fn (PeriodResult $result): int => $result->secondsToSolve ?? 0, $counted)), 60),
            xpVisible: $xpVisible,
            xpGained: $player['xp_gained'],
            levelBefore: LevelTable::levelForXp(max(0, $xpAtEnd - $player['xp_gained'])),
            levelAfter: LevelTable::levelForXp($xpAtEnd),
            levelProgress: LevelTable::progressToNext($xpAtEnd),
            isMember: $isMember,
            achievementsEarned: $achievements,
            achievementPointsGained: array_sum(array_map(
                static fn (BadgeResult $badge): int => $badge->tier?->points() ?? BadgeTier::SINGLE_TIER_POINTS,
                $achievements,
            )),
            rating: $player['ranking_opted_out'] ? null : $this->ratingChange($playerId, $period, $isMember),
            streakDays: $player['streak_opted_out'] ? 0 : $this->currentStreak($playerId),
            favoritesActivity: $this->favoritesActivity($playerId, $player['favorite_players'], $period),
            mostSolved: $this->mostSolved($period),
            nextAchievements: $isMember ? $this->nextAchievements($playerId) : [],
            karmaGained: $this->karmaGained(),
        );
    }

    /**
     * @throws PlayerNotFound
     * @return array{xp_total: int, xp_gained: int, xp_after: int, experience_system_opted_out: bool, ranking_opted_out: bool, streak_opted_out: bool, favorite_players: list<string>}
     */
    private function player(string $playerId, DigestPeriod $period): array
    {
        // XP of the period = the live ledger lines earned in it (in_weekly_delta leaves out the launch backfill and
        // the later difficulty/speed settlements, as the weekly XP board does); xp_after takes the lines earned
        // after the period off today's total, so the level is the one the player had when the period ended
        $sql = <<<SQL
SELECT
    p.xp_total,
    p.experience_system_opted_out,
    p.ranking_opted_out,
    p.streak_opted_out,
    p.favorite_players,
    COALESCE((
        SELECT SUM(x.amount) FROM xp_entry x
        WHERE x.player_id = p.id AND x.in_weekly_delta = true
          AND x.earned_at >= CAST(:periodStart AS TIMESTAMP) AND x.earned_at < CAST(:periodEnd AS TIMESTAMP)
    ), 0) AS xp_gained,
    COALESCE((
        SELECT SUM(x.amount) FROM xp_entry x
        WHERE x.player_id = p.id AND x.earned_at >= CAST(:periodEnd AS TIMESTAMP)
    ), 0) AS xp_after
FROM player p
WHERE p.id = :playerId
SQL;

        /** @var false|array{xp_total: int|string, experience_system_opted_out: bool, ranking_opted_out: bool, streak_opted_out: bool, favorite_players: null|string, xp_gained: int|string, xp_after: int|string} $row */
        $row = $this->database->executeQuery($sql, [
            'playerId' => $playerId,
            'periodStart' => $period->startUtc(),
            'periodEnd' => $period->endUtc(),
        ])->fetchAssociative();

        if ($row === false) {
            throw new PlayerNotFound();
        }

        $favorites = is_string($row['favorite_players']) ? json_decode($row['favorite_players'], true) : [];

        return [
            'xp_total' => (int) $row['xp_total'],
            'xp_gained' => (int) $row['xp_gained'],
            'xp_after' => (int) $row['xp_after'],
            'experience_system_opted_out' => (bool) $row['experience_system_opted_out'],
            'ranking_opted_out' => (bool) $row['ranking_opted_out'],
            'streak_opted_out' => (bool) $row['streak_opted_out'],
            'favorite_players' => is_array($favorites) ? array_values(array_filter($favorites, 'is_string')) : [],
        ];
    }

    /**
     * Solo results and the pair/team results the player is a registered member of, solved in the period. Results
     * awaiting verification come along for the owner's list, marked - they are left out of every number.
     *
     * @return list<PeriodResult>
     */
    private function results(string $playerId, DigestPeriod $period): array
    {
        $sql = <<<SQL
SELECT
    pst.id AS time_id,
    pst.puzzle_id,
    pz.name AS puzzle_name,
    m.name AS brand_name,
    COALESCE(pst.pieces_count_snapshot, pz.pieces_count) AS pieces_count,
    CASE WHEN pz.hide_image_until IS NOT NULL AND pz.hide_image_until > CAST(:now AS TIMESTAMP) THEN NULL ELSE pz.image END AS puzzle_image,
    pst.seconds_to_solve,
    COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
    pst.puzzling_type,
    pst.puzzlers_count,
    pst.first_attempt,
    pst.suspicious,
    COALESCE(xp.amount, 0) AS xp,
    (
        pst.puzzling_type = 'solo'
        AND pst.player_id = :playerId
        AND pst.seconds_to_solve IS NOT NULL
        AND pst.suspicious = false
        AND earlier.best IS NOT NULL
        AND pst.seconds_to_solve < earlier.best
    ) AS personal_best
FROM puzzle_solving_time pst
INNER JOIN puzzle pz ON pz.id = pst.puzzle_id
INNER JOIN manufacturer m ON m.id = pz.manufacturer_id
LEFT JOIN LATERAL (
    SELECT SUM(x.amount) AS amount FROM xp_entry x WHERE x.player_id = :playerId AND x.solving_time_id = pst.id
) xp ON true
LEFT JOIN LATERAL (
    SELECT MIN(e.seconds_to_solve) AS best
    FROM puzzle_solving_time e
    WHERE e.player_id = :playerId
      AND e.puzzle_id = pst.puzzle_id
      AND e.puzzling_type = 'solo'
      AND e.suspicious = false
      AND e.seconds_to_solve IS NOT NULL
      AND e.id != pst.id
      AND COALESCE(e.finished_at, e.tracked_at) < COALESCE(pst.finished_at, pst.tracked_at)
) earlier ON pst.puzzling_type = 'solo'
WHERE (
        pst.player_id = :playerId
        OR pst.puzzling_team_id IN (SELECT ptm.team_id FROM puzzling_team_member ptm WHERE ptm.player_id = :playerId)
    )
  AND pst.tracked_at >= CAST(:periodStart AS TIMESTAMP)
  AND COALESCE(pst.finished_at, pst.tracked_at) >= CAST(:periodStart AS TIMESTAMP)
  AND COALESCE(pst.finished_at, pst.tracked_at) < CAST(:periodEnd AS TIMESTAMP)
ORDER BY COALESCE(pst.finished_at, pst.tracked_at) DESC, pst.id DESC
SQL;

        /** @var list<array{time_id: string, puzzle_id: string, puzzle_name: string, brand_name: string, pieces_count: int|string, puzzle_image: null|string, seconds_to_solve: null|int|string, solved_at: string, puzzling_type: string, puzzlers_count: null|int|string, first_attempt: bool, suspicious: bool, xp: int|string, personal_best: null|bool}> $rows */
        $rows = $this->database->executeQuery($sql, [
            'playerId' => $playerId,
            'periodStart' => $period->startUtc(),
            'periodEnd' => $period->endUtc(),
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        return array_map(static fn (array $row): PeriodResult => new PeriodResult(
            timeId: $row['time_id'],
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            brandName: $row['brand_name'],
            piecesCount: (int) $row['pieces_count'],
            puzzleImage: $row['puzzle_image'],
            secondsToSolve: $row['seconds_to_solve'] === null ? null : (int) $row['seconds_to_solve'],
            solvedAt: new DateTimeImmutable($row['solved_at']),
            puzzlingType: $row['puzzling_type'],
            puzzlersCount: max(1, (int) $row['puzzlers_count']),
            firstAttempt: $row['first_attempt'],
            personalBest: $row['personal_best'] === true,
            awaitingVerification: $row['suspicious'],
            xp: (int) $row['xp'],
        ), $rows);
    }

    /**
     * @param list<PeriodResult> $results
     * @return list<PeriodResult>
     */
    private function withPlaces(string $playerId, array $results): array
    {
        $rankings = $this->getRanking->allForPlayer($playerId);

        return array_map(static function (PeriodResult $result) use ($rankings): PeriodResult {
            $ranking = $result->isSolo() ? ($rankings[$result->puzzleId] ?? null) : null;

            return $ranking === null ? $result : $result->withRank($ranking->rank, $ranking->totalPlayers);
        }, $results);
    }

    /**
     * @return list<BadgeResult>
     */
    private function achievementsEarned(string $playerId, DigestPeriod $period): array
    {
        return array_values(array_filter(
            $this->getBadges->allEarnedTiers($playerId),
            static fn (BadgeResult $badge): bool => $badge->earnedAt >= $period->start && $badge->earnedAt < $period->end,
        ));
    }

    /**
     * Place on the MSP Rating ladder today + the movement over the period from the daily snapshots (the snapshot
     * before the period against the last one inside it); skill tier changes for members.
     */
    private function ratingChange(string $playerId, DigestPeriod $period, bool $isMember): null|PeriodRatingChange
    {
        $sql = <<<SQL
SELECT
    pieces.pieces_count,
    before_period.elo_rank AS rank_before,
    before_period.skill_tier AS tier_before,
    in_period.elo_rank AS rank_after,
    in_period.skill_tier AS tier_after
FROM (SELECT DISTINCT pieces_count FROM player_rating_snapshot WHERE player_id = :playerId) pieces
LEFT JOIN LATERAL (
    SELECT s.elo_rank, s.skill_tier FROM player_rating_snapshot s
    WHERE s.player_id = :playerId AND s.pieces_count = pieces.pieces_count AND s.snapshot_date < CAST(:periodStart AS TIMESTAMP)
    ORDER BY s.snapshot_date DESC LIMIT 1
) before_period ON true
LEFT JOIN LATERAL (
    SELECT s.elo_rank, s.skill_tier FROM player_rating_snapshot s
    WHERE s.player_id = :playerId AND s.pieces_count = pieces.pieces_count
      AND s.snapshot_date >= CAST(:periodStart AS TIMESTAMP) AND s.snapshot_date < CAST(:periodEnd AS TIMESTAMP)
    ORDER BY s.snapshot_date DESC LIMIT 1
) in_period ON true
SQL;

        /** @var list<array{pieces_count: int|string, rank_before: null|int|string, tier_before: null|int|string, rank_after: null|int|string, tier_after: null|int|string}> $rows */
        $rows = $this->database->executeQuery($sql, [
            'playerId' => $playerId,
            // Snapshots are dated by their (UTC) day
            'periodStart' => $period->start->format('Y-m-d'),
            'periodEnd' => $period->end->format('Y-m-d'),
        ])->fetchAllAssociative();

        $placesGained = 0;
        $tierChanges = [];

        foreach ($rows as $row) {
            $piecesCount = (int) $row['pieces_count'];

            if ($piecesCount === self::RATING_PIECES_COUNT && $row['rank_before'] !== null && $row['rank_after'] !== null) {
                $placesGained = (int) $row['rank_before'] - (int) $row['rank_after'];
            }

            $tierAfter = $row['tier_after'] === null ? null : SkillTier::tryFrom((int) $row['tier_after']);
            $tierBefore = $row['tier_before'] === null ? null : SkillTier::tryFrom((int) $row['tier_before']);

            if ($isMember && $tierAfter !== null && $tierAfter !== $tierBefore) {
                $tierChanges[$piecesCount] = ['before' => $tierBefore, 'after' => $tierAfter];
            }
        }

        $ladderPlace = $this->getPlayerRatingRanking->allForPlayer($playerId)[self::RATING_PIECES_COUNT] ?? null;

        if ($ladderPlace === null && $tierChanges === []) {
            return null;
        }

        ksort($tierChanges);

        return new PeriodRatingChange(
            rank: $ladderPlace['rank'] ?? null,
            rankTotal: $ladderPlace['total'] ?? null,
            placesGained: $ladderPlace === null ? 0 : $placesGained,
            skillTierChanges: $tierChanges,
        );
    }

    private function currentStreak(string $playerId): int
    {
        $sql = <<<SQL
SELECT DISTINCT TO_CHAR(COALESCE(finished_at, tracked_at), 'YYYY-MM-DD') AS solve_day
FROM puzzle_solving_time
WHERE suspicious = false
  AND COALESCE(finished_at, tracked_at) >= '2000-01-01'
  AND (
    player_id = :playerId
    OR (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
  )
ORDER BY solve_day
SQL;

        /** @var list<array{solve_day: string}> $rows */
        $rows = $this->database->executeQuery($sql, ['playerId' => $playerId])->fetchAllAssociative();

        return $this->streakCalculator->calculate(array_column($rows, 'solve_day'))->current;
    }

    /**
     * @param list<string> $favoriteIds
     * @return list<array{playerId: string, name: string, solves: int}>
     */
    private function favoritesActivity(string $playerId, array $favoriteIds, DigestPeriod $period): array
    {
        if ($favoriteIds === []) {
            return [];
        }

        // Rendered in a consumer too, so there is no viewer for HiddenPlayers / PrivateProfileAccess: the player's own
        // blocks (docs/features/player-blocklist.md - admin blocks never touch the favourites) and the allow list
        // (docs/features/private-profile-allow-list.md - a block either way outranks it) are applied here
        $sql = <<<SQL
SELECT p.id AS player_id, COALESCE(p.name, '#' || UPPER(p.code)) AS name, COUNT(*) AS solves
FROM puzzle_solving_time pst
JOIN player p ON p.id = pst.player_id
WHERE pst.player_id IN (:favoriteIds)
  AND pst.suspicious = false
  AND NOT EXISTS (SELECT 1 FROM user_block ub WHERE ub.blocker_id = :recipientId AND ub.blocked_id = p.id)
  AND (
    p.is_private = false
    OR (
      EXISTS (SELECT 1 FROM private_profile_viewer ppv WHERE ppv.owner_id = p.id AND ppv.viewer_id = :recipientId)
      AND NOT EXISTS (SELECT 1 FROM user_block ub WHERE ub.blocker_id = p.id AND ub.blocked_id = :recipientId)
    )
  )
  AND pst.tracked_at >= CAST(:periodStart AS TIMESTAMP)
  AND COALESCE(pst.finished_at, pst.tracked_at) >= CAST(:periodStart AS TIMESTAMP)
  AND COALESCE(pst.finished_at, pst.tracked_at) < CAST(:periodEnd AS TIMESTAMP)
GROUP BY p.id, p.name, p.code
ORDER BY solves DESC, name ASC
LIMIT 5
SQL;

        /** @var list<array{player_id: string, name: string, solves: int|string}> $rows */
        $rows = $this->database->executeQuery(
            $sql,
            [
                'favoriteIds' => $favoriteIds,
                'recipientId' => $playerId,
                'periodStart' => $period->startUtc(),
                'periodEnd' => $period->endUtc(),
            ],
            ['favoriteIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => ['playerId' => $row['player_id'], 'name' => $row['name'], 'solves' => (int) $row['solves']],
            $rows,
        );
    }

    /**
     * The puzzle the most puzzlers solved in the period - the same for every recipient. Hidden (secret) puzzles are
     * left out.
     *
     * @return null|array{puzzleId: string, name: string, solvers: int}
     */
    private function mostSolved(DigestPeriod $period): null|array
    {
        $sql = <<<SQL
SELECT pz.id AS puzzle_id, pz.name, COUNT(DISTINCT pst.player_id) AS solvers
FROM puzzle_solving_time pst
JOIN puzzle pz ON pz.id = pst.puzzle_id
WHERE pst.suspicious = false
  AND (pz.hide_until IS NULL OR pz.hide_until <= CAST(:now AS TIMESTAMP))
  AND pst.tracked_at >= CAST(:periodStart AS TIMESTAMP)
  AND COALESCE(pst.finished_at, pst.tracked_at) >= CAST(:periodStart AS TIMESTAMP)
  AND COALESCE(pst.finished_at, pst.tracked_at) < CAST(:periodEnd AS TIMESTAMP)
GROUP BY pz.id, pz.name
HAVING COUNT(DISTINCT pst.player_id) >= 3
ORDER BY solvers DESC, pz.name ASC
LIMIT 1
SQL;

        /** @var false|array{puzzle_id: string, name: string, solvers: int|string} $row */
        $row = $this->database->executeQuery($sql, [
            'periodStart' => $period->startUtc(),
            'periodEnd' => $period->endUtc(),
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return ['puzzleId' => $row['puzzle_id'], 'name' => $row['name'], 'solvers' => (int) $row['solvers']];
    }

    /**
     * The tiers closest to being earned - a member's "what next".
     *
     * @return list<array{type: BadgeType, progress: BadgeProgress}>
     */
    private function nextAchievements(string $playerId): array
    {
        $snapshot = $this->getPlayerStatsSnapshot->forPlayer($playerId);

        $highestEarned = [];
        foreach ($this->getBadges->forPlayer($playerId) as $badge) {
            if ($badge->tier !== null) {
                $highestEarned[$badge->type->value] = $badge->tier;
            }
        }

        $candidates = [];

        foreach ($this->conditions as $condition) {
            $type = $condition->badgeType();
            $earned = $highestEarned[$type->value] ?? null;

            if ($earned === BadgeTier::Diamond) {
                continue;
            }

            $progress = $condition->progressToNextTier($snapshot, $earned);

            if ($progress === null || $progress->percent <= 0) {
                continue;
            }

            $candidates[] = ['type' => $type, 'progress' => $progress];
        }

        usort($candidates, static fn (array $a, array $b): int => $b['progress']->percent <=> $a['progress']->percent);

        return array_slice($candidates, 0, 3);
    }

    /**
     * Workstream F hook: karma gained in the period (accepted contributions), from its karma history read model.
     * Null = karma does not exist yet; the e-mail and the page leave the karma line out.
     */
    private function karmaGained(): null|int
    {
        /** @var null|int $karma until workstream F: always null */
        $karma = null;

        return $karma;
    }
}
