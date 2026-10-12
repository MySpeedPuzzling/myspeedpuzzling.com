<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Badges;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The metrics of PlayerStatsSnapshot, grown one event at a time (AchievementFirstMetDates). Every metric only grows -
 * a best time only falls - so once a tier is met it stays met for the rest of the replay.
 *
 * @phpstan-type ReplayEvent array{
 *     kind: string,
 *     at: null|string,
 *     own: bool,
 *     puzzle: string,
 *     pieces: int,
 *     manufacturer: null|string,
 *     seconds: null|int,
 *     type: string,
 *     firstAttempt: bool,
 *     unboxed: bool,
 *     photo: bool,
 *     day: string,
 *     dow: int,
 *     dated: bool,
 *     quarter: int,
 * }
 */
#[Exclude]
final class AchievementReplayState
{
    /** @var array<string, true> */
    private array $puzzles = [];

    private int $pieces = 0;

    private null|int $best500 = null;

    private null|int $best1000 = null;

    /** @var array<int, int> streak runs: first day → last day (day numbers) */
    private array $runEnds = [];

    /** @var array<int, int> streak runs: last day → first day */
    private array $runStarts = [];

    /** @var array<int, true> */
    private array $days = [];

    private int $longestStreak = 0;

    private int $teamSolves = 0;

    private int $zen = 0;

    private int $firstTry = 0;

    private int $unboxed = 0;

    /** @var array<string, true> */
    private array $manufacturers = [];

    private int $marathoner = 0;

    private int $photographer = 0;

    private int $weekend = 0;

    /** @var array<int, true> year * 4 + quarter */
    private array $quarters = [];

    private int $longestQuarters = 0;

    private int $librarian = 0;

    private int $cataloger = 0;

    public function __construct(
        readonly private string $playerId,
    ) {
    }

    /**
     * A result row of AchievementFirstMetDates
     *
     * @param array<string, mixed> $row
     * @return ReplayEvent
     */
    public static function solve(array $row): array
    {
        return [
            'kind' => 'solve',
            'at' => is_string($row['at'] ?? null) ? $row['at'] : null,
            'own' => (bool) ($row['own'] ?? false),
            'puzzle' => is_string($row['puzzle_id'] ?? null) ? $row['puzzle_id'] : '',
            'pieces' => self::int($row['pieces_count'] ?? null) ?? 0,
            'manufacturer' => is_string($row['manufacturer_id'] ?? null) ? $row['manufacturer_id'] : null,
            'seconds' => self::int($row['seconds_to_solve'] ?? null),
            'type' => is_string($row['puzzling_type'] ?? null) ? $row['puzzling_type'] : '',
            'firstAttempt' => (bool) ($row['first_attempt'] ?? false),
            'unboxed' => (bool) ($row['unboxed'] ?? false),
            'photo' => (bool) ($row['photo'] ?? false),
            'day' => is_string($row['solve_day'] ?? null) ? $row['solve_day'] : '1970-01-01',
            'dow' => self::int($row['iso_dow'] ?? null) ?? 0,
            'dated' => (bool) ($row['dated'] ?? false),
            'quarter' => (self::int($row['solve_year'] ?? null) ?? 0) * 4 + (self::int($row['solve_quarter'] ?? null) ?? 0),
        ];
    }

    /**
     * An accepted proposal (librarian) or an approved puzzle (cataloger)
     *
     * @return ReplayEvent
     */
    public static function other(string $kind, mixed $at): array
    {
        return [
            'kind' => $kind,
            'at' => is_string($at) ? $at : null,
            'own' => false,
            'puzzle' => '',
            'pieces' => 0,
            'manufacturer' => null,
            'seconds' => null,
            'type' => '',
            'firstAttempt' => false,
            'unboxed' => false,
            'photo' => false,
            'day' => '1970-01-01',
            'dow' => 0,
            'dated' => false,
            'quarter' => 0,
        ];
    }

    /**
     * @param ReplayEvent $event
     */
    public function apply(array $event): void
    {
        if ($event['kind'] === 'librarian') {
            $this->librarian++;

            return;
        }

        if ($event['kind'] === 'cataloger') {
            $this->cataloger++;

            return;
        }

        $pieces = $event['pieces'];
        $seconds = $event['seconds'];
        $type = $event['type'];

        // Participation: the player's own results and the pair/team results they were in
        $this->puzzles[$event['puzzle']] = true;
        $this->pieces += $pieces;
        $this->addDay($event['day']);

        if ($type === 'duo' || $type === 'team') {
            $this->teamSolves++;
        }

        if ($event['dated']) {
            $this->addQuarter($event['quarter']);
        }

        if ($event['own'] === false) {
            return;
        }

        // The player's own results (player_id = them, solo or a group they tracked)
        if ($seconds === null) {
            $this->zen++;
        }

        if ($event['firstAttempt']) {
            $this->firstTry++;
        }

        if ($event['unboxed']) {
            $this->unboxed++;
        }

        if ($event['manufacturer'] !== null) {
            $this->manufacturers[$event['manufacturer']] = true;
        }

        if ($pieces >= 2000) {
            $this->marathoner++;
        }

        if ($event['photo']) {
            $this->photographer++;
        }

        if (($event['dow'] === 6 || $event['dow'] === 7) && $event['dated']) {
            $this->weekend++;
        }

        if ($type === 'solo' && $seconds !== null) {
            if ($pieces === 500) {
                $this->best500 = $this->best500 === null ? $seconds : min($this->best500, $seconds);
            }

            if ($pieces === 1000) {
                $this->best1000 = $this->best1000 === null ? $seconds : min($this->best1000, $seconds);
            }
        }
    }

    public function snapshot(): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: $this->playerId,
            distinctPuzzlesSolved: count($this->puzzles),
            totalPiecesSolved: $this->pieces,
            best500PieceSoloSeconds: $this->best500,
            allTimeLongestStreakDays: $this->longestStreak,
            teamSolvesCount: $this->teamSolves,
            zenPuzzlerSolves: $this->zen,
            firstTrySolves: $this->firstTry,
            unboxedSolves: $this->unboxed,
            brandExplorerManufacturers: count($this->manufacturers),
            marathonerSolves: $this->marathoner,
            photographerSolves: $this->photographer,
            steadyHandsQuarters: $this->longestQuarters,
            librarianApprovedRequests: $this->librarian,
            best1000PieceSoloSeconds: $this->best1000,
            weekendSolves: $this->weekend,
            catalogerApprovedPuzzles: $this->cataloger,
        );
    }

    /**
     * Consecutive calendar days like ActivityCalendarStreakCalculator: a new day joins the run ending the day before
     * and the run starting the day after
     */
    private function addDay(string $day): void
    {
        $number = intdiv((new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone('UTC')))->getTimestamp(), 86400);

        if (isset($this->days[$number])) {
            return;
        }

        $this->days[$number] = true;
        $first = $this->runStarts[$number - 1] ?? $number;
        $last = $this->runEnds[$number + 1] ?? $number;
        unset($this->runStarts[$number - 1], $this->runEnds[$number + 1]);
        $this->runEnds[$first] = $last;
        $this->runStarts[$last] = $first;
        $this->longestStreak = max($this->longestStreak, $last - $first + 1);
    }

    private function addQuarter(int $index): void
    {
        if (isset($this->quarters[$index])) {
            return;
        }

        $this->quarters[$index] = true;
        $indexes = array_keys($this->quarters);
        sort($indexes);
        $run = 0;
        $previous = null;

        foreach ($indexes as $quarter) {
            $run = $previous !== null && $quarter === $previous + 1 ? $run + 1 : 1;
            $this->longestQuarters = max($this->longestQuarters, $run);
            $previous = $quarter;
        }
    }

    private static function int(mixed $value): null|int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
