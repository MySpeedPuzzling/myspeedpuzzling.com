<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CommunityScope;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * Numbers of one community scope from community_scope_stats (docs/features/players-page/README.md).
 */
readonly final class CommunityScopeStatistics
{
    // The Country Cup ranks countries per active puzzler only from this many active puzzlers up
    public const int CUP_MINIMUM_ACTIVE = 10;

    /**
     * @param list<int> $monthlySolves 12 months, oldest first, the current month last
     */
    public function __construct(
        public CommunityScope $scope,
        public int $registeredPlayers,
        public int $active30d,
        public int $solves30d,
        public int $solvesPrev30d,
        public int $activeThisMonth,
        public int $piecesThisMonth,
        public int $activeLastMonth,
        public int $piecesLastMonth,
        public null|int $medianBest500Seconds,
        public int $puzzlersWith500,
        public array $monthlySolves,
        public int $newFaces14d,
        public null|DateTimeImmutable $computedAt,
    ) {
    }

    /**
     * @param array{
     *     scope: string,
     *     registered_players: int|string,
     *     active30d: int|string,
     *     solves30d: int|string,
     *     solves_prev30d: int|string,
     *     active_this_month: int|string,
     *     pieces_this_month: int|string,
     *     active_last_month: int|string,
     *     pieces_last_month: int|string,
     *     median_best500_seconds: null|int|string,
     *     puzzlers_with500: int|string,
     *     monthly_solves: string,
     *     new_faces14d: int|string,
     *     computed_at: string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        /** @var mixed $decoded */
        $decoded = json_decode($row['monthly_solves'], true);
        $monthly = is_array($decoded) ? array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $decoded)) : [];

        return new self(
            scope: CommunityScope::fromQuery($row['scope']),
            registeredPlayers: (int) $row['registered_players'],
            active30d: (int) $row['active30d'],
            solves30d: (int) $row['solves30d'],
            solvesPrev30d: (int) $row['solves_prev30d'],
            activeThisMonth: (int) $row['active_this_month'],
            piecesThisMonth: (int) $row['pieces_this_month'],
            activeLastMonth: (int) $row['active_last_month'],
            piecesLastMonth: (int) $row['pieces_last_month'],
            medianBest500Seconds: $row['median_best500_seconds'] === null ? null : (int) $row['median_best500_seconds'],
            puzzlersWith500: (int) $row['puzzlers_with500'],
            monthlySolves: $monthly,
            newFaces14d: (int) $row['new_faces14d'],
            computedAt: new DateTimeImmutable($row['computed_at']),
        );
    }

    /**
     * A scope nobody computed yet (fresh deploy before the first cron run, or a country without players): zeros.
     */
    public static function empty(CommunityScope $scope): self
    {
        return new self($scope, 0, 0, 0, 0, 0, 0, 0, 0, null, 0, array_fill(0, 12, 0), 0, null);
    }

    public function country(): null|CountryCode
    {
        return $this->scope->country;
    }

    /**
     * Change of solves in the last 30 days against the 30 before, in whole percent; null when there is nothing to
     * compare with.
     */
    public function solvesChangePercent(): null|int
    {
        if ($this->solvesPrev30d === 0) {
            return null;
        }

        return (int) round(($this->solves30d - $this->solvesPrev30d) / $this->solvesPrev30d * 100);
    }

    /**
     * A country with fewer active puzzlers than this gets the spotlight's "small and growing" note
     */
    public const int SMALL_COMMUNITY_ACTIVE = 15;

    public function isSmallCommunity(): bool
    {
        return $this->scope->isWorld() === false && $this->active30d < self::SMALL_COMMUNITY_ACTIVE;
    }

    /**
     * How many seconds faster this scope's median best 500 is than another scope's (the world): positive = faster,
     * negative = slower; null when either has no 500 time.
     */
    public function medianBest500FasterThan(self $other): null|int
    {
        if ($this->medianBest500Seconds === null || $other->medianBest500Seconds === null) {
            return null;
        }

        return $other->medianBest500Seconds - $this->medianBest500Seconds;
    }

    /**
     * Pieces placed this month per active puzzler - the Country Cup's default measure. Null below the minimum of
     * active puzzlers, so one very busy person cannot top the Cup.
     */
    public function piecesPerActivePuzzlerThisMonth(): null|int
    {
        if ($this->activeThisMonth < self::CUP_MINIMUM_ACTIVE) {
            return null;
        }

        return intdiv($this->piecesThisMonth, $this->activeThisMonth);
    }

    /**
     * The same measure for last month - the Cup's final standings, shown in the first days of a month.
     */
    public function piecesPerActivePuzzlerLastMonth(): null|int
    {
        if ($this->activeLastMonth < self::CUP_MINIMUM_ACTIVE) {
            return null;
        }

        return intdiv($this->piecesLastMonth, $this->activeLastMonth);
    }
}
