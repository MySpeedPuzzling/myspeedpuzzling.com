<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\PuzzlingTeamMember;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * "Never twice" against what the player already has (docs/features/data-import/adding.md "Never twice", README
 * "Never twice" steps 1, 2 and 4) - asked for one row at a time, under the player's import lock (ImportWriteLock), so
 * what it reads cannot change before the row is written.
 *
 * **What the player has** = their results of the puzzle as they are now: tracked by them, or of a pair/team they are a
 * registered member of. A result that was deleted or undone is not there. **A result pairs off with one row only**: one
 * that a row of any of the player's imports made, or was settled as "already yours" with, is spoken for.
 *
 * The day of a result is its solve day, else the day it was logged. People are not compared: a guest "Ben" and the
 * registered Ben are the same solve.
 *
 * **A date known to its month or year is compared within that period, never by its stored first day**
 * (docs/features/data-import/date-precision.md "Never twice"): a row is a result when the result lies in the row's
 * period and is known at least as exactly as the row. So "March 2021" read again is already yours, "March 2021" is
 * the result typed for 14 March 2021, and a row of 1 March 2021 is never a result that only knows "March 2021".
 *
 * DQL only - nothing here names the results' table (the suspicious-time and trackerless-time guards list raw SQL):
 * a result waiting for verification is the player's like any other, and a result without a tracker is found through
 * its pair/team.
 */
readonly final class ImportNeverTwice
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DataImportRowRepository $rowRepository,
    ) {
    }

    /**
     * The player's results of the puzzle no row has spoken for yet.
     *
     * @return list<array{id: string, day: string, precision: DatePrecision, seconds: null|int, type: PuzzlingType}>
     */
    public function freeResultsOnProfile(Player $player, Puzzle $puzzle): array
    {
        $results = $this->resultsOnProfile($player, $puzzle);

        if ($results === []) {
            return [];
        }

        $spokenFor = $this->rowRepository->solvingTimeIdsSpokenFor(
            $player->id->toString(),
            array_map(static fn (array $result): string => $result['id'], $results),
        );

        return array_values(array_filter($results, static fn (array $result): bool => isset($spokenFor[$result['id']]) === false));
    }

    /**
     * Whether the player has any result of the puzzle - what keeps a puzzle off the wish list.
     */
    public function hasSolved(Player $player, Puzzle $puzzle): bool
    {
        return $this->resultsOnProfile($player, $puzzle) !== [];
    }

    /**
     * The player's results of the puzzle that a row of this import speaks for already - what a coarser row of the
     * same file is compared with (README "Never twice", step 2: "a coarser row that fits a finer, equal row of the
     * same import is the same result as that row, not a second one").
     *
     * @return list<array{id: string, day: string, precision: DatePrecision, seconds: null|int, type: PuzzlingType}>
     */
    public function resultsOfThisImport(Player $player, Puzzle $puzzle, string $importId): array
    {
        $results = $this->resultsOnProfile($player, $puzzle);

        if ($results === []) {
            return [];
        }

        $spokenFor = $this->rowRepository->solvingTimeIdsSpokenFor(
            $player->id->toString(),
            array_map(static fn (array $result): string => $result['id'], $results),
            $importId,
        );

        return array_values(array_filter($results, static fn (array $result): bool => isset($spokenFor[$result['id']])));
    }

    /**
     * The free result that is this row: the same day - or, for a row known to its month or year, a day or month in
     * that period - time and solo/pair/team. The one the pairing's hint names comes first.
     *
     * A row without a time and without an exact day is any free result of the puzzle (README step 2, the last pile):
     * one of its period and kind first.
     *
     * @param list<array{id: string, day: string, precision: DatePrecision, seconds: null|int, type: PuzzlingType}> $free
     * @param string $day Y-m-d; the first day of the period for a month or a year
     */
    public static function equal(array $free, string $day, null|int $seconds, PuzzlingType $type, null|string $hintedId, DatePrecision $precision = DatePrecision::Day): null|string
    {
        $found = null;

        foreach ($free as $result) {
            if (self::fits($result, $day, $precision) === false || $result['seconds'] !== $seconds || $result['type'] !== $type) {
                continue;
            }

            if ($result['id'] === $hintedId) {
                return $result['id'];
            }

            $found ??= $result['id'];
        }

        if ($found !== null || $precision === DatePrecision::Day || $seconds !== null) {
            return $found;
        }

        // "Solved, 2021" without a time: any result of the puzzle nobody spoke for
        $best = null;
        $bestScore = -1;

        foreach ($free as $result) {
            $score = (self::fits($result, $day, $precision) ? 2 : 0) + ($result['type'] === $type ? 1 : 0);

            if ($score > $bestScore) {
                $best = $result['id'];
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * A free result that may be this row written differently (README step 4) - the player is asked, nothing is added
     * by itself. Two exact days: the same time on another day, or the same day with another time. A month or a year
     * on either side: the same time - in another period, or in the same one where the result knows less than the row
     * ("another time in the same year" says nothing: a puzzle is solved again and again).
     *
     * @param list<array{id: string, day: string, precision: DatePrecision, seconds: null|int, type: PuzzlingType}> $free
     */
    public static function similar(array $free, string $day, null|int $seconds, DatePrecision $precision = DatePrecision::Day): null|string
    {
        foreach ($free as $result) {
            $sameTime = $seconds !== null && $result['seconds'] === $seconds;

            if ($precision === DatePrecision::Day && $result['precision'] === DatePrecision::Day) {
                if (($result['day'] === $day) !== $sameTime) {
                    return $result['id'];
                }

                continue;
            }

            if ($sameTime) {
                return $result['id'];
            }
        }

        return null;
    }

    /**
     * Whether the result lies in the row's period and is known at least as exactly as the row.
     *
     * @param array{id: string, day: string, precision: DatePrecision, seconds: null|int, type: PuzzlingType} $result
     */
    private static function fits(array $result, string $day, DatePrecision $precision): bool
    {
        if (self::rank($result['precision']) > self::rank($precision)) {
            return false;
        }

        return $precision->holds(new DateTimeImmutable($day), new DateTimeImmutable($result['day']));
    }

    private static function rank(DatePrecision $precision): int
    {
        return match ($precision) {
            DatePrecision::Day => 0,
            DatePrecision::Month => 1,
            DatePrecision::Year => 2,
        };
    }

    /**
     * A pair's or team's row: whether somebody else - a result the player is not in - has a pair/team result of the
     * puzzle with this day (or in this month or year, when either side knows no more) and time. Two partners importing the same evening would otherwise leave two public
     * results of one solve.
     */
    public function somebodyElseHasIt(Player $player, Puzzle $puzzle, string $day, int $seconds, DatePrecision $precision = DatePrecision::Day): bool
    {
        /** @var list<array{id: \Ramsey\Uuid\UuidInterface, finishedAt: null|DateTimeImmutable, trackedAt: DateTimeImmutable, finishedAtPrecision: null|DatePrecision}> $others */
        $others = $this->entityManager->createQueryBuilder()
            ->select('time.id', 'time.finishedAt', 'time.trackedAt', 'time.finishedAtPrecision')
            ->from(PuzzleSolvingTime::class, 'time')
            ->where('time.puzzle = :puzzle')
            ->andWhere('time.secondsToSolve = :seconds')
            ->andWhere('time.puzzlingType != :solo')
            ->andWhere('time.player IS NULL OR time.player != :player')
            ->andWhere('NOT EXISTS (SELECT 1 FROM ' . PuzzlingTeamMember::class . ' teamMember WHERE teamMember.team = time.puzzlingTeam AND teamMember.player = :player)')
            ->setParameter('puzzle', $puzzle)
            ->setParameter('seconds', $seconds)
            ->setParameter('solo', PuzzlingType::Solo)
            ->setParameter('player', $player)
            ->setMaxResults(50)
            ->getQuery()
            ->getArrayResult();

        foreach ($others as $other) {
            // Within the coarser of the two: their "March 2021" and this row's 14 March 2021 may be one evening
            $coarser = self::rank($other['finishedAtPrecision'] ?? DatePrecision::Day) > self::rank($precision)
                ? $other['finishedAtPrecision'] ?? DatePrecision::Day
                : $precision;

            if ($coarser->holds(new DateTimeImmutable($day), $other['finishedAt'] ?? $other['trackedAt'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{id: string, day: string, precision: DatePrecision, seconds: null|int, type: PuzzlingType}>
     */
    private function resultsOnProfile(Player $player, Puzzle $puzzle): array
    {
        /** @var list<array{id: \Ramsey\Uuid\UuidInterface, secondsToSolve: null|int, finishedAt: null|DateTimeImmutable, trackedAt: DateTimeImmutable, puzzlingType: PuzzlingType, finishedAtPrecision: null|DatePrecision}> $results */
        $results = $this->entityManager->createQueryBuilder()
            ->select('time.id', 'time.secondsToSolve', 'time.finishedAt', 'time.trackedAt', 'time.puzzlingType', 'time.finishedAtPrecision')
            ->from(PuzzleSolvingTime::class, 'time')
            ->where('time.puzzle = :puzzle')
            ->andWhere('time.player = :player OR EXISTS (SELECT 1 FROM ' . PuzzlingTeamMember::class . ' teamMember WHERE teamMember.team = time.puzzlingTeam AND teamMember.player = :player)')
            ->setParameter('puzzle', $puzzle)
            ->setParameter('player', $player)
            ->orderBy('time.id')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $result): array => [
            'id' => $result['id']->toString(),
            'day' => ($result['finishedAt'] ?? $result['trackedAt'])->format('Y-m-d'),
            // A result without a solve day is dated by when it was logged - a real day
            'precision' => $result['finishedAt'] === null ? DatePrecision::Day : $result['finishedAtPrecision'] ?? DatePrecision::Day,
            'seconds' => $result['secondsToSolve'],
            'type' => $result['puzzlingType'],
        ], $results);
    }
}
