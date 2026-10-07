<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\SuspiciousTimePuzzleCard;
use SpeedPuzzling\Web\Results\SuspiciousTimePuzzleCardCase;
use SpeedPuzzling\Web\Results\SuspiciousTimeQueueCounts;
use SpeedPuzzling\Web\Results\SuspiciousTimeQueuePage;
use SpeedPuzzling\Web\Value\ExpectedTimeSource;
use SpeedPuzzling\Web\Value\PuzzleSecrecy;
use SpeedPuzzling\Web\Value\SuspicionDirection;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeQueueTab;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeResponse;
use SpeedPuzzling\Web\Value\SuspiciousTimeTier;

/**
 * Which cases the time verification queue lists, in which order (docs/features/suspicious-time-review.md, "Moderator
 * queue"); GetSuspiciousTimeCaseDetail loads what a card shows. Reads only suspicious_time_* and what the cases point
 * at - admins and moderators see every queued time, a private player's included (Jan, 2026-10-07). Times on a secret
 * competition puzzle (PuzzleSecrecy) stay out until the reveal, like in every other moderator queue.
 *
 * "Too fast" / "Too slow" list pending cases of their direction: strong before possible, then by impact. For a fast
 * time the impact is its leaderboard place - counted exactly only for the cards of the listed page (an exact place
 * for every pending case costs ~45 ms on the production copy), so the order uses what puzzle_statistics already knows:
 * the time leads its puzzle (#1) first, then bigger leaderboards first, then the score. A slow time takes no place
 * anybody cares about - by score (how many times slower) after the tier.
 *
 * Pending cases of a puzzle that makes a puzzle card ("is the piece count right?") are shown in the card, not in the
 * list: several different players raised on it in that direction making a fifth of its comparable results (a popular
 * puzzle collects a few raised times by chance), or its difficulty far below (fast) or above (slow) the usual - unless
 * a moderator confirmed its current piece count.
 */
readonly final class GetSuspiciousTimeQueue
{
    public const int PER_PAGE = 30;
    // A puzzle card: this many different players raised on one puzzle in one direction ...
    public const int CARD_MIN_PLAYERS = 2;
    // ... whose raised results are at least this share of the puzzle's comparable results: its results of the types
    // raised (solo; pair/team below the slow floor) as puzzle_statistics counts them - not flagged, the raised included
    public const float CARD_MIN_SHARE = 0.2;
    // ... or players are usually this much faster on the puzzle than their level (puzzle_difficulty.difficulty_score)
    public const float CARD_EASY_BELOW = 0.5;
    // ... or this much slower - the mirror for the "Too slow" tab
    public const float CARD_HARD_ABOVE = 2.0;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function counts(): SuspiciousTimeQueueCounts
    {
        $notSecret = PuzzleSecrecy::sqlNotSecret('p');
        $query = <<<SQL
SELECT
    COUNT(*) FILTER (WHERE c.status = :pending AND c.direction = :fast) AS fast,
    COUNT(*) FILTER (WHERE c.status = :pending AND c.direction = :slow) AS slow,
    COUNT(*) FILTER (WHERE c.status = :marked AND (c.player_edited_at IS NOT NULL OR EXISTS (
        SELECT 1 FROM suspicious_time_notice n
        WHERE n.case_id = c.id AND n.marked_at = c.marked_at AND n.response = :saysCorrect AND n.answer IS NULL
    ))) AS replied,
    COUNT(*) FILTER (WHERE c.status = :marked) AS marked,
    COUNT(*) FILTER (WHERE c.status = :trusted) AS trusted,
    (SELECT COUNT(*) FROM suspicious_time_decision) AS decisions
FROM suspicious_time_case c
JOIN puzzle_solving_time pst ON pst.id = c.time_id
JOIN puzzle p ON p.id = pst.puzzle_id
WHERE {$notSecret}
SQL;

        /** @var array{fast: int|string, slow: int|string, replied: int|string, marked: int|string, trusted: int|string, decisions: int|string} $row */
        $row = $this->database->fetchAssociative($query, [
            'pending' => SuspiciousTimeCaseStatus::Pending->value,
            'marked' => SuspiciousTimeCaseStatus::Marked->value,
            'trusted' => SuspiciousTimeCaseStatus::Trusted->value,
            'fast' => SuspicionDirection::Fast->value,
            'slow' => SuspicionDirection::Slow->value,
            'saysCorrect' => SuspiciousTimeResponse::SaysCorrect->value,
            'now' => $this->now(),
        ]);

        return new SuspiciousTimeQueueCounts(
            fast: (int) $row['fast'],
            slow: (int) $row['slow'],
            replied: (int) $row['replied'],
            marked: (int) $row['marked'],
            trusted: (int) $row['trusted'],
            decisions: (int) $row['decisions'],
        );
    }

    /**
     * The number next to "Time verification" in the key menu: one count over the (status, direction) index. Unlike
     * the tabs it does not leave out times on secret puzzles - it runs on every page an admin or moderator opens.
     */
    public function countPending(): int
    {
        $count = $this->database->fetchOne(
            'SELECT COUNT(*) FROM suspicious_time_case WHERE status = :pending',
            ['pending' => SuspiciousTimeCaseStatus::Pending->value],
        );
        assert(is_int($count));

        return $count;
    }

    /**
     * Puzzle cards of a direction, most players first.
     *
     * @return list<SuspiciousTimePuzzleCard>
     */
    public function puzzleCards(SuspicionDirection $direction): array
    {
        $query = $this->pendingWithCardsSql($direction) . <<<SQL
SELECT
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.pieces_count,
    p.image,
    m.name AS manufacturer_name,
    pd.difficulty_score,
    COUNT(DISTINCT pe.player_id) AS players,
    COALESCE(ps.solved_times_solo_count, 0) AS solo_results,
    ps.median_time_solo,
    ps.fastest_time_solo,
    json_agg(json_build_object(
        'case_id', pe.id,
        'time_id', pe.time_id,
        'player_id', pl.id,
        'player_name', pl.name,
        'player_code', pl.code,
        'player_private', pl.is_private,
        'seconds', pe.seconds_to_solve,
        'puzzlers', pe.puzzlers_count,
        'expected_seconds', pe.expected_seconds,
        'expected_source', pe.expected_source,
        'tier', pe.tier,
        'reasons', pe.reasons
    ) ORDER BY pe.tier = :strong DESC, pe.score DESC NULLS LAST, pe.id) AS cases
FROM pending pe
JOIN card_puzzle cp ON cp.puzzle_id = pe.puzzle_id
JOIN puzzle p ON p.id = pe.puzzle_id
JOIN player pl ON pl.id = pe.player_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = p.id
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = p.id
GROUP BY p.id, m.name, pd.difficulty_score, ps.solved_times_solo_count, ps.median_time_solo, ps.fastest_time_solo
ORDER BY COUNT(DISTINCT pe.player_id) DESC, p.name, p.id
SQL;

        /** @var list<array{puzzle_id: string, puzzle_name: string, pieces_count: int, image: null|string, manufacturer_name: null|string, difficulty_score: null|float|string, players: int|string, solo_results: int|string, median_time_solo: null|int, fastest_time_solo: null|int, cases: string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, $this->pendingParameters($direction));

        return array_map(static function (array $row) use ($direction): SuspiciousTimePuzzleCard {
            /** @var list<array{case_id: string, time_id: string, player_id: string, player_name: null|string, player_code: string, player_private: bool, seconds: null|int, puzzlers: int, expected_seconds: null|int, expected_source: null|string, tier: null|string, reasons: array<mixed>}> $cases */
            $cases = json_decode($row['cases'], true, flags: JSON_THROW_ON_ERROR);

            return new SuspiciousTimePuzzleCard(
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                manufacturerName: $row['manufacturer_name'],
                piecesCount: $row['pieces_count'],
                image: $row['image'],
                difficultyScore: $row['difficulty_score'] !== null ? (float) $row['difficulty_score'] : null,
                playersCount: (int) $row['players'],
                soloResults: (int) $row['solo_results'],
                medianSolo: $row['median_time_solo'],
                fastestSolo: $row['fastest_time_solo'],
                direction: $direction,
                cases: array_map(static fn (array $case): SuspiciousTimePuzzleCardCase => new SuspiciousTimePuzzleCardCase(
                    caseId: $case['case_id'],
                    timeId: $case['time_id'],
                    playerId: $case['player_id'],
                    playerName: $case['player_name'],
                    playerCode: $case['player_code'],
                    playerPrivate: $case['player_private'],
                    seconds: $case['seconds'],
                    puzzlersCount: $case['puzzlers'],
                    expectedSeconds: $case['expected_seconds'],
                    expectedSource: $case['expected_source'] !== null ? ExpectedTimeSource::from($case['expected_source']) : null,
                    tier: $case['tier'] !== null ? SuspiciousTimeTier::from($case['tier']) : null,
                    reasons: SuspiciousTimeReason::listFromArray($case['reasons']),
                ), $cases),
            );
        }, $rows);
    }

    /**
     * The pending cases of a direction outside the puzzle cards, one page in queue order.
     */
    public function pending(SuspicionDirection $direction, int $page): SuspiciousTimeQueuePage
    {
        $order = $direction === SuspicionDirection::Fast
            // Leads its puzzle (the statistics count the pending time itself), then bigger leaderboards first
            ? '(pe.seconds_to_solve <= ps.fastest_time_solo) DESC NULLS LAST, ps.solved_times_solo_count DESC NULLS LAST, pe.score DESC NULLS LAST'
            : 'pe.score DESC NULLS LAST';

        $query = $this->pendingWithCardsSql($direction) . <<<SQL
SELECT pe.id, COUNT(*) OVER () AS total
FROM pending pe
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = pe.puzzle_id
WHERE pe.puzzle_id NOT IN (SELECT puzzle_id FROM card_puzzle)
ORDER BY pe.tier = :strong DESC, {$order}, pe.id
LIMIT :limit OFFSET :offset
SQL;

        /** @var list<array{id: string, total: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            ...$this->pendingParameters($direction),
            'limit' => self::PER_PAGE,
            'offset' => self::offset($page),
        ]);

        return new SuspiciousTimeQueuePage(
            caseIds: array_column($rows, 'id'),
            total: $rows !== [] ? (int) $rows[0]['total'] : 0,
        );
    }

    /**
     * One page of "Player replied" (oldest waiting first), "Needs verification" or "Verified fine" (newest decision
     * first). The tab counts are the totals.
     *
     * @return list<string>
     */
    public function decidedCaseIds(SuspiciousTimeQueueTab $tab, int $page): array
    {
        [$condition, $order] = match ($tab) {
            SuspiciousTimeQueueTab::Replied => [
                'c.status = :marked AND (c.player_edited_at IS NOT NULL OR reply.replied_at IS NOT NULL)',
                'LEAST(c.player_edited_at, reply.replied_at) ASC, c.id',
            ],
            SuspiciousTimeQueueTab::Marked => ['c.status = :marked', 'c.marked_at DESC NULLS LAST, c.id DESC'],
            SuspiciousTimeQueueTab::Trusted => ['c.status = :trusted', 'c.decided_at DESC NULLS LAST, c.id DESC'],
            default => throw new \LogicException(sprintf('Tab "%s" lists no decided cases.', $tab->value)),
        };

        $notSecret = PuzzleSecrecy::sqlNotSecret('p');
        $query = <<<SQL
SELECT c.id
FROM suspicious_time_case c
JOIN puzzle_solving_time pst ON pst.id = c.time_id
JOIN puzzle p ON p.id = pst.puzzle_id
LEFT JOIN LATERAL (
    SELECT MIN(n.responded_at) AS replied_at
    FROM suspicious_time_notice n
    WHERE n.case_id = c.id AND n.marked_at = c.marked_at AND n.response = :saysCorrect AND n.answer IS NULL
) reply ON c.status = :marked
WHERE {$condition} AND {$notSecret}
ORDER BY {$order}
LIMIT :limit OFFSET :offset
SQL;

        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn($query, [
            'marked' => SuspiciousTimeCaseStatus::Marked->value,
            'trusted' => SuspiciousTimeCaseStatus::Trusted->value,
            'saysCorrect' => SuspiciousTimeResponse::SaysCorrect->value,
            'now' => $this->now(),
            'limit' => self::PER_PAGE,
            'offset' => self::offset($page),
        ]);

        return $ids;
    }

    /**
     * The CTEs `pending` (the direction's pending cases on puzzles that are not secret) and `card_puzzle` (the
     * puzzles making a card), followed by the caller's SELECT. The comparable results come from puzzle_statistics
     * (counting them in puzzle_solving_time cost ~8 ms more on the production copy - popular puzzles have thousands).
     */
    private function pendingWithCardsSql(SuspicionDirection $direction): string
    {
        $notSecret = PuzzleSecrecy::sqlNotSecret('p');
        $farOff = $direction === SuspicionDirection::Fast
            ? 'candidate.difficulty_score < :easyBelow'
            : 'candidate.difficulty_score > :hardAbove';

        return <<<SQL
WITH pending AS (
    SELECT
        c.id, c.tier, c.score, c.reasons, c.expected_seconds, c.expected_source,
        pst.id AS time_id, pst.puzzle_id, pst.player_id, pst.seconds_to_solve, pst.puzzlers_count, pst.puzzling_type,
        p.pieces_count
    FROM suspicious_time_case c
    JOIN puzzle_solving_time pst ON pst.id = c.time_id
    JOIN puzzle p ON p.id = pst.puzzle_id
    WHERE c.status = :pending AND c.direction = :direction AND {$notSecret}
),
card_candidate AS (
    SELECT
        pe.puzzle_id,
        pd.difficulty_score,
        COUNT(DISTINCT pe.player_id) AS players,
        COUNT(*) AS raised,
        array_agg(DISTINCT pe.puzzling_type) AS puzzling_types
    FROM pending pe
    LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = pe.puzzle_id
    WHERE NOT EXISTS (
        SELECT 1 FROM suspicious_time_puzzle_confirmation conf
        WHERE conf.puzzle_id = pe.puzzle_id AND conf.pieces_count = pe.pieces_count
    )
    GROUP BY pe.puzzle_id, pd.difficulty_score
),
card_puzzle AS (
    SELECT candidate.puzzle_id
    FROM card_candidate candidate
    WHERE {$farOff}
        OR (
            candidate.players >= :cardMinPlayers
            AND candidate.raised >= CAST(:cardMinShare AS double precision) * COALESCE((
                SELECT
                    CASE WHEN 'solo' = ANY(candidate.puzzling_types) THEN ps.solved_times_solo_count ELSE 0 END
                    + CASE WHEN 'duo' = ANY(candidate.puzzling_types) THEN ps.solved_times_duo_count ELSE 0 END
                    + CASE WHEN 'team' = ANY(candidate.puzzling_types) THEN ps.solved_times_team_count ELSE 0 END
                FROM puzzle_statistics ps
                WHERE ps.puzzle_id = candidate.puzzle_id
            ), 0)
        )
)

SQL;
    }

    /**
     * @return array<string, int|float|string>
     */
    private function pendingParameters(SuspicionDirection $direction): array
    {
        $parameters = [
            'pending' => SuspiciousTimeCaseStatus::Pending->value,
            'direction' => $direction->value,
            'strong' => SuspiciousTimeTier::Strong->value,
            'cardMinPlayers' => self::CARD_MIN_PLAYERS,
            'cardMinShare' => self::CARD_MIN_SHARE,
            'now' => $this->now(),
        ];

        if ($direction === SuspicionDirection::Fast) {
            $parameters['easyBelow'] = self::CARD_EASY_BELOW;
        } else {
            $parameters['hardAbove'] = self::CARD_HARD_ABOVE;
        }

        return $parameters;
    }

    private static function offset(int $page): int
    {
        return (max(1, $page) - 1) * self::PER_PAGE;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
