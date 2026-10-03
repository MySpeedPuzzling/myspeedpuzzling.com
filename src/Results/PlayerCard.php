<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\SkillTier;

/**
 * The numbers and facts of the player card on the Players page (docs/features/players-page/README.md): one row of
 * community_player_stats with the player's MSP Rating, skill tier and a few cheap facts. Who the player is comes from
 * GetPlayerProfile, which also decides whether the viewer may see them.
 */
readonly final class PlayerCard
{
    /**
     * @param null|list<int> $monthlySolves 12 calendar months, oldest first, the current month last - loaded for
     *                                      members only (null otherwise)
     */
    public function __construct(
        public int $solvedTotal,
        public int $piecesTotal,
        public int $favoritesCount,
        public null|int $best500Seconds,
        public null|int $best1000Seconds,
        public null|DateTimeImmutable $lastSolvedAt,
        public null|array $monthlySolves,
        public bool $rankingOptedOut,
        // MSP Rating on 500 pieces (elo × 1000, rounded); null when not rated or opted out of rankings
        public null|int $mspRating,
        // Skill tier on 500 pieces; null when none yet or opted out of rankings - shown to members only
        public null|SkillTier $skillTier,
        public bool $competesInEvents,
        public bool $swapsPuzzles,
        public bool $onInstagram,
    ) {
    }

    /**
     * @param array{
     *     solved_total: int|string,
     *     pieces_total: int|string,
     *     favorites_count: int|string,
     *     best500_seconds: null|int|string,
     *     best1000_seconds: null|int|string,
     *     last_solved_at: null|string,
     *     monthly_solves: null|string,
     *     ranking_opted_out: bool,
     *     elo_rating: null|float|string,
     *     skill_tier: null|int|string,
     *     competes_in_events: bool,
     *     swaps_puzzles: bool,
     *     on_instagram: bool,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $monthly = null;

        if ($row['monthly_solves'] !== null) {
            /** @var mixed $decoded */
            $decoded = json_decode($row['monthly_solves'], true);
            $monthly = is_array($decoded)
                ? array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $decoded))
                : [];
        }

        return new self(
            solvedTotal: (int) $row['solved_total'],
            piecesTotal: (int) $row['pieces_total'],
            favoritesCount: (int) $row['favorites_count'],
            best500Seconds: $row['best500_seconds'] === null ? null : (int) $row['best500_seconds'],
            best1000Seconds: $row['best1000_seconds'] === null ? null : (int) $row['best1000_seconds'],
            lastSolvedAt: $row['last_solved_at'] === null ? null : new DateTimeImmutable($row['last_solved_at']),
            monthlySolves: $monthly,
            rankingOptedOut: $row['ranking_opted_out'],
            mspRating: $row['elo_rating'] === null ? null : (int) round((float) $row['elo_rating'] * 1000),
            skillTier: $row['skill_tier'] === null ? null : SkillTier::tryFrom((int) $row['skill_tier']),
            competesInEvents: $row['competes_in_events'],
            swapsPuzzles: $row['swaps_puzzles'],
            onInstagram: $row['on_instagram'],
        );
    }

    /**
     * The busiest month of the activity strip, so the bars scale to it (at least 1, an empty year draws flat bars).
     */
    public function busiestMonth(): int
    {
        return max([1, ...($this->monthlySolves ?? [])]);
    }

    /**
     * The strip's bars with their months: the stats cron runs every 15 minutes, so its last month is the current one.
     *
     * @return list<array{month: DateTimeImmutable, solved: int}> oldest first
     */
    public function activity(DateTimeImmutable $now): array
    {
        $monthlySolves = $this->monthlySolves ?? [];
        // The 15th at noon: the month stays the same whichever timezone formats it
        $currentMonth = $now->modify('first day of this month')->setTime(12, 0)->modify('+14 days');
        $count = count($monthlySolves);
        $bars = [];

        foreach ($monthlySolves as $index => $solved) {
            $bars[] = [
                'month' => $currentMonth->modify(sprintf('-%d months', $count - 1 - $index)),
                'solved' => $solved,
            ];
        }

        return $bars;
    }
}
