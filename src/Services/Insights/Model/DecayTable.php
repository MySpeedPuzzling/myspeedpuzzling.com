<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights\Model;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingFormula;

/**
 * MspRatingFormula::decay() of entries at "now + n months", memoised per (solve moment, month): most entries share a
 * day, and the forecast asks for the same pairs in every scenario. One instance per computation.
 */
final class DecayTable
{
    /** @var array<int, DateTimeImmutable> */
    private array $months = [];

    /** @var array<string, float> */
    private array $values = [];

    public function __construct(
        private readonly DateTimeImmutable $now,
    ) {
    }

    public function at(DateTimeImmutable $solvedAt, int $monthsAhead): float
    {
        $key = $solvedAt->getTimestamp() . '|' . $monthsAhead;

        return $this->values[$key] ??= MspRatingFormula::decay($solvedAt, $this->month($monthsAhead));
    }

    /**
     * The moment a result of month $month (1 = the coming month) is placed at: the middle of that month.
     */
    public function placedIn(int $month): DateTimeImmutable
    {
        return $this->month($month - 1)->modify('+15 days');
    }

    private function month(int $monthsAhead): DateTimeImmutable
    {
        return $this->months[$monthsAhead] ??= $this->now->modify("+{$monthsAhead} months");
    }
}
