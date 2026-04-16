<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetPlayerActivityCalendar;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Results\ActivityCalendarDay;
use SpeedPuzzling\Web\Results\ActivityCalendarStreak;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\ActivityCalendarStreakCalculator;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent]
final class ActivityCalendar
{
    use DefaultActionTrait;

    #[LiveProp]
    public null|string $playerId = null;

    #[LiveProp(writable: true)]
    public null|int $year = null;

    #[LiveProp(writable: true)]
    public null|int $month = null;

    #[LiveProp(writable: true)]
    public null|string $selectedDay = null;

    #[LiveProp]
    public bool $streakOptedOut = false;

    /** @var array<string, ActivityCalendarDay> */
    public array $daysByDate = [];

    /** @var array<int, int> 0 = Mon .. 6 = Sun */
    public array $dowBuckets = [];

    /** @var array<int, int> 0..23 */
    public array $hourBuckets = [];

    public ActivityCalendarStreak $streak;

    /** @var list<SolvedPuzzle> */
    public array $selectedDaySolvings = [];

    public null|Chart $hourOfDayChart = null;

    public function __construct(
        readonly private GetPlayerActivityCalendar $getPlayerActivityCalendar,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private ActivityCalendarStreakCalculator $streakCalculator,
        readonly private ChartBuilderInterface $chartBuilder,
        readonly private ClockInterface $clock,
    ) {
        $this->streak = new ActivityCalendarStreak(current: 0, longest: 0, currentStreakDates: []);
    }

    #[PostMount]
    #[PreReRender]
    public function populate(): void
    {
        $playerId = $this->playerId;
        assert($playerId !== null);

        $this->clampYearMonth();

        $year = $this->year;
        $month = $this->month;
        assert($year !== null && $month !== null);

        $this->daysByDate = $this->getPlayerActivityCalendar->perDayInMonth($playerId, $year, $month);

        $activeDays = $this->getPlayerActivityCalendar->activeDaysInMonth($playerId, $year, $month);
        $this->streak = $this->streakCalculator->calculate($activeDays);
        $this->dowBuckets = $this->getPlayerActivityCalendar->dayOfWeekBucketsInMonth($playerId, $year, $month);
        $this->hourBuckets = $this->getPlayerActivityCalendar->hourOfDayBucketsInMonth($playerId, $year, $month);
        $this->hourOfDayChart = $this->buildHourOfDayChart($this->hourBuckets);

        $this->selectedDaySolvings = $this->loadSelectedDaySolvings();
    }

    #[LiveAction]
    public function prevMonth(): void
    {
        $this->clampYearMonth();
        assert($this->year !== null && $this->month !== null);

        $date = new DateTimeImmutable(sprintf('%04d-%02d-01', $this->year, $this->month));
        $date = $date->modify('-1 month');

        $this->year = (int) $date->format('Y');
        $this->month = (int) $date->format('m');
        $this->selectedDay = null;
    }

    #[LiveAction]
    public function nextMonth(): void
    {
        $this->clampYearMonth();
        assert($this->year !== null && $this->month !== null);

        $date = new DateTimeImmutable(sprintf('%04d-%02d-01', $this->year, $this->month));
        $date = $date->modify('+1 month');

        $this->year = (int) $date->format('Y');
        $this->month = (int) $date->format('m');
        $this->selectedDay = null;
    }

    #[LiveAction]
    public function selectDay(#[LiveArg] string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }

        $this->selectedDay = $date;
    }

    #[LiveAction]
    public function clearSelection(): void
    {
        $this->selectedDay = null;
    }

    /**
     * @return list<array{date: null|DateTimeImmutable, day: null|ActivityCalendarDay, inMonth: bool, isToday: bool, isSelected: bool, isInCurrentStreak: bool}>
     */
    public function getGridCells(): array
    {
        assert($this->year !== null && $this->month !== null);

        $firstOfMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', $this->year, $this->month));
        $lastOfMonth = $firstOfMonth->modify('last day of this month');

        // ISO day-of-week: Mon=1..Sun=7, convert to leading blanks count (Mon-start week).
        $leading = ((int) $firstOfMonth->format('N')) - 1;

        $today = $this->clock->now()->format('Y-m-d');
        $streakSet = array_fill_keys($this->streak->currentStreakDates, true);

        $cells = [];

        for ($i = 0; $i < $leading; $i++) {
            $cells[] = [
                'date' => null,
                'day' => null,
                'inMonth' => false,
                'isToday' => false,
                'isSelected' => false,
                'isInCurrentStreak' => false,
            ];
        }

        for ($day = $firstOfMonth; $day <= $lastOfMonth; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $cells[] = [
                'date' => $day,
                'day' => $this->daysByDate[$key] ?? null,
                'inMonth' => true,
                'isToday' => $key === $today,
                'isSelected' => $key === $this->selectedDay,
                'isInCurrentStreak' => isset($streakSet[$key]),
            ];
        }

        // Pad trailing blanks so rows of 7 are complete (grid looks stable across months).
        while (count($cells) % 7 !== 0) {
            $cells[] = [
                'date' => null,
                'day' => null,
                'inMonth' => false,
                'isToday' => false,
                'isSelected' => false,
                'isInCurrentStreak' => false,
            ];
        }

        return $cells;
    }

    public function getMonthLabel(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function getDowMax(): int
    {
        $max = 0;
        foreach ($this->dowBuckets as $count) {
            $max = max($max, $count);
        }

        return $max;
    }

    public function hasActivityThisMonth(): bool
    {
        return $this->daysByDate !== [];
    }

    private function clampYearMonth(): void
    {
        $now = $this->clock->now();
        $currentYear = (int) $now->format('Y');
        $currentMonth = (int) $now->format('m');

        if ($this->year === null || $this->year < 2015 || $this->year > $currentYear) {
            $this->year = $currentYear;
        }

        if ($this->month === null || $this->month < 1 || $this->month > 12) {
            $this->month = $currentMonth;
        }

        // Don't let future months through when combined with the current year.
        if ($this->year === $currentYear && $this->month > $currentMonth) {
            $this->month = $currentMonth;
        }
    }

    /**
     * @return list<SolvedPuzzle>
     */
    private function loadSelectedDaySolvings(): array
    {
        if ($this->selectedDay === null || $this->playerId === null) {
            return [];
        }

        $dayStart = DateTimeImmutable::createFromFormat('!Y-m-d', $this->selectedDay);

        if ($dayStart === false) {
            return [];
        }

        $dayEnd = $dayStart->setTime(23, 59, 59);

        $solo = $this->getPlayerSolvedPuzzles->soloByPlayerId($this->playerId, $dayStart, $dayEnd);
        $duo = $this->getPlayerSolvedPuzzles->duoByPlayerId($this->playerId, $dayStart, $dayEnd);
        $team = $this->getPlayerSolvedPuzzles->teamByPlayerId($this->playerId, $dayStart, $dayEnd);

        $merged = array_merge($solo, $duo, $team);

        usort($merged, static function (SolvedPuzzle $a, SolvedPuzzle $b): int {
            $aDate = $a->finishedAt ?? $a->trackedAt;
            $bDate = $b->finishedAt ?? $b->trackedAt;

            return $bDate <=> $aDate;
        });

        return $merged;
    }

    /**
     * @param array<int, int> $hourBuckets
     */
    private function buildHourOfDayChart(array $hourBuckets): Chart
    {
        $labels = [];
        $data = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $labels[] = sprintf('%02d', $hour);
            $data[] = $hourBuckets[$hour] ?? 0;
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => $data,
                    'backgroundColor' => 'rgba(254, 64, 66, 0.6)',
                    'borderColor' => '#fe4042',
                    'borderWidth' => 1,
                    'borderRadius' => 2,
                ],
            ],
        ]);
        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
            'scales' => [
                'x' => [
                    'grid' => [
                        'display' => false,
                    ],
                ],
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ]);

        return $chart;
    }
}
