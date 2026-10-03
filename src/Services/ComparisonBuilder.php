<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\ComparisonCell;
use SpeedPuzzling\Web\Results\ComparisonHeadToHead;
use SpeedPuzzling\Web\Results\ComparisonLeagueRow;
use SpeedPuzzling\Web\Results\ComparisonPuzzleRow;
use SpeedPuzzling\Web\Results\ComparisonResult;
use SpeedPuzzling\Web\Results\ComparisonSubject;
use SpeedPuzzling\Web\Results\ComparisonTimeRow;
use SpeedPuzzling\Web\Value\ComparisonCriteria;
use SpeedPuzzling\Web\Value\ComparisonSort;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use SpeedPuzzling\Web\Value\ComparisonTimes;

/**
 * Turns the aggregate rows (GetComparisonResults) into the comparison view model - pure PHP, no queries, no state
 * (docs/features/player-comparison.md). Ranks per puzzle, wins, league table, head-to-head, sorting and the paging
 * window; the charts are ComparisonChartsData's job on top of the result.
 *
 * Rules where the spec leaves room:
 * - Everything is counted over the shown puzzles ("puzzles to show" applied), so the summary describes the list.
 * - Equal times share a rank and nobody wins the puzzle. Ties elsewhere are broken by line-up order, then puzzle id.
 * - Highlight pair: the criteria's A/B when they are in the line-up; otherwise you (or your pair/team) vs the most
 *   recently added other subject, or the first two when you are not in the line-up. A picked alone is paired with you,
 *   or with the first other subject.
 * - Sort: recent = latest day among the compared cells; lead / lag = A − B seconds (puzzles one of them has not solved
 *   follow, most recent first); name = natural, case-insensitive; pieces ascending; difficulty hardest first, unrated
 *   last.
 * - Highlighted pair only (the Duel view of 3+ subjects): the list - rows, page, total - holds just the puzzles both
 *   highlighted subjects solved, while the league table, head to head and the matrix keep describing the whole line-up
 *   under its "puzzles to show". With two subjects the flag changes nothing: their duel rows follow "puzzles to show".
 */
readonly final class ComparisonBuilder
{
    /**
     * @param list<ComparisonSubject> $subjects the line-up in its order, oldest added first; unavailable ones are skipped
     * @param list<ComparisonTimeRow> $rows GetComparisonResults::forSubjects() for the available subjects
     * @param bool $highlightedPairOnly list only the puzzles both highlighted subjects solved (3+ subjects)
     */
    public function build(array $subjects, array $rows, ComparisonCriteria $criteria, bool $highlightedPairOnly = false): ComparisonResult
    {
        $refs = [];
        $self = null;

        foreach ($subjects as $subject) {
            if ($subject->isAvailable === false || isset($refs[$subject->ref->toString()])) {
                continue;
            }

            $refs[$subject->ref->toString()] = $subject->ref;

            if ($self === null && $subject->isSelf()) {
                $self = $subject->ref;
            }
        }

        $refs = array_values($refs);
        [$highlightA, $highlightB] = self::highlightPair($refs, $self, $criteria);

        $puzzleRows = $this->puzzleRows($refs, $rows, $criteria, $highlightA, $highlightB);
        $puzzleRows = self::sorted($puzzleRows, $criteria->sort);
        [$beats, $shared] = self::matrix($refs, $puzzleRows);

        $pairOnly = $highlightedPairOnly && count($refs) >= 3 && $highlightA !== null && $highlightB !== null;
        $listRows = $pairOnly ? self::solvedByBoth($puzzleRows, $highlightA, $highlightB) : $puzzleRows;

        $total = count($listRows);
        $page = array_slice($listRows, $criteria->offset, $criteria->limit);
        $shownUntil = min($total, $criteria->offset + $criteria->limit);

        return new ComparisonResult(
            criteria: $criteria,
            subjects: $refs,
            self: $self,
            highlightA: $highlightA,
            highlightB: $highlightB,
            rows: $listRows,
            page: $page,
            pagePuzzleIds: array_map(static fn(ComparisonPuzzleRow $row): string => $row->puzzleId, $page),
            total: $total,
            hasMore: $shownUntil < $total,
            remaining: max(0, $total - $shownUntil),
            league: self::league($refs, $self, $puzzleRows),
            headToHead: $highlightA !== null && $highlightB !== null ? self::headToHead($highlightA, $highlightB, $puzzleRows) : null,
            beats: $beats,
            shared: $shared,
            highlightedPairOnly: $pairOnly,
        );
    }

    /**
     * @param list<ComparisonSubjectRef> $refs
     * @return array{null|ComparisonSubjectRef, null|ComparisonSubjectRef}
     */
    private static function highlightPair(array $refs, null|ComparisonSubjectRef $self, ComparisonCriteria $criteria): array
    {
        if ($refs === []) {
            return [null, null];
        }

        $inLineUp = static function (null|ComparisonSubjectRef $ref) use ($refs): null|ComparisonSubjectRef {
            foreach ($refs as $candidate) {
                if ($ref !== null && $candidate->equals($ref)) {
                    return $candidate;
                }
            }

            return null;
        };
        $firstOther = static function (ComparisonSubjectRef $than) use ($refs): null|ComparisonSubjectRef {
            foreach ($refs as $candidate) {
                if ($candidate->equals($than) === false) {
                    return $candidate;
                }
            }

            return null;
        };
        $lastOther = static function (ComparisonSubjectRef $than) use ($refs): null|ComparisonSubjectRef {
            foreach (array_reverse($refs) as $candidate) {
                if ($candidate->equals($than) === false) {
                    return $candidate;
                }
            }

            return null;
        };

        $a = $inLineUp($criteria->highlightA);
        $b = $inLineUp($criteria->highlightB);

        if ($a !== null && $b !== null && $a->equals($b)) {
            $b = null;
        }

        if ($a === null) {
            if ($b === null) {
                $a = $self ?? $refs[0];
            } else {
                $a = $self !== null && $self->equals($b) === false ? $self : $firstOther($b);
            }
        }

        if ($a === null) {
            return [$b, null];
        }

        if ($b === null) {
            if ($self !== null && $self->equals($a)) {
                // You vs the one you added last
                $b = $lastOther($a);
            } else {
                $b = $self ?? $firstOther($a);
            }
        }

        return [$a, $b];
    }

    /**
     * @param list<ComparisonSubjectRef> $refs
     * @param list<ComparisonTimeRow> $rows
     * @return list<ComparisonPuzzleRow>
     */
    private function puzzleRows(
        array $refs,
        array $rows,
        ComparisonCriteria $criteria,
        null|ComparisonSubjectRef $highlightA,
        null|ComparisonSubjectRef $highlightB,
    ): array {
        $firstTries = $criteria->times === ComparisonTimes::FirstTries;
        $refsByKey = [];

        foreach ($refs as $ref) {
            $refsByKey[$ref->toString()] = $ref;
        }

        /** @var array<string, array<string, ComparisonTimeRow>> $byPuzzle */
        $byPuzzle = [];

        foreach ($rows as $row) {
            $key = $row->subject->toString();

            if (isset($refsByKey[$key]) === false || ($firstTries && $row->firstTrySeconds === null)) {
                continue;
            }

            $byPuzzle[$row->puzzleId][$key] = $row;
        }

        $minimumSolvers = $criteria->show->minimumSolvers(count($refs));
        $keyA = $highlightA?->toString();
        $keyB = $highlightB?->toString();
        $puzzleRows = [];

        // Thousands of puzzles for a big line-up: plain loops, no closures per puzzle
        foreach ($byPuzzle as $puzzleId => $subjectRows) {
            if (count($subjectRows) < $minimumSolvers) {
                continue;
            }

            // The compared seconds in line-up order, and who has not solved it
            $seconds = [];
            $notSolvedBy = [];

            foreach ($refsByKey as $key => $ref) {
                $row = $subjectRows[$key] ?? null;

                if ($row === null) {
                    $notSolvedBy[] = $ref;
                } else {
                    $seconds[$key] = $firstTries ? (int) $row->firstTrySeconds : $row->bestSeconds;
                }
            }

            if ($seconds === []) {
                continue;
            }

            // asort() is stable: equal times keep the line-up order; equal times share the rank (1, 1, 3)
            $sorted = $seconds;
            asort($sorted);
            $ranks = [];
            $position = 0;
            $rank = 0;
            $previous = null;

            foreach ($sorted as $key => $value) {
                $position++;

                if ($value !== $previous) {
                    $rank = $position;
                    $previous = $value;
                }

                $ranks[$key] = $rank;
            }

            $fastest = $sorted[array_key_first($sorted)];
            $cells = [];
            $latestDay = null;
            $anyRow = null;

            foreach ($seconds as $key => $value) {
                $row = $subjectRows[$key];
                $anyRow ??= $row;
                $day = $firstTries ? ($row->firstTryDay ?? $row->bestDay) : $row->bestDay;

                $cells[$key] = new ComparisonCell(
                    subject: $row->subject,
                    seconds: $value,
                    timeId: $firstTries ? (string) $row->firstTryTimeId : $row->bestTimeId,
                    day: $day,
                    attempts: $row->attempts,
                    bestSeconds: $row->bestSeconds,
                    bestTimeId: $row->bestTimeId,
                    firstTrySeconds: $row->firstTrySeconds,
                    firstTryTimeId: $row->firstTryTimeId,
                    rank: $ranks[$key],
                    deltaSeconds: $value - $fastest,
                    deltaRatio: $fastest > 0 ? $value / $fastest - 1 : 0.0,
                );

                if ($latestDay === null || $day > $latestDay) {
                    $latestDay = $day;
                }
            }

            $ranked = [];

            foreach (array_keys($sorted) as $key) {
                $ranked[] = $cells[$key];
            }

            $solvers = count($cells);
            // The unique fastest: the runner-up does not share rank 1
            $winner = $solvers >= 2 && $ranked[1]->rank > 1 ? $ranked[0]->subject : null;
            $cellA = $keyA !== null ? ($cells[$keyA] ?? null) : null;
            $cellB = $keyB !== null ? ($cells[$keyB] ?? null) : null;

            $puzzleRows[] = new ComparisonPuzzleRow(
                puzzleId: (string) $puzzleId,
                piecesCount: $anyRow->piecesCount,
                difficultyTier: $anyRow->difficultyTier,
                puzzleName: $anyRow->puzzleName,
                cells: $cells,
                ranked: $ranked,
                notSolvedBy: $notSolvedBy,
                fastestSeconds: $fastest,
                winner: $winner,
                latestDay: $latestDay,
                lead: $cellA !== null && $cellB !== null ? $cellA->seconds - $cellB->seconds : null,
            );
        }

        return $puzzleRows;
    }

    /**
     * The puzzles both of the pair solved, in the given order
     *
     * @param list<ComparisonPuzzleRow> $rows
     * @return list<ComparisonPuzzleRow>
     */
    private static function solvedByBoth(array $rows, ComparisonSubjectRef $a, ComparisonSubjectRef $b): array
    {
        return array_values(array_filter(
            $rows,
            static fn(ComparisonPuzzleRow $row): bool => $row->cell($a) !== null && $row->cell($b) !== null,
        ));
    }

    /**
     * @param list<ComparisonPuzzleRow> $rows
     * @return list<ComparisonPuzzleRow>
     */
    private static function sorted(array $rows, ComparisonSort $sort): array
    {
        // Scalar keys computed once - thousands of rows, a comparison must not build arrays of objects
        $keys = [];

        foreach ($rows as $index => $row) {
            $recent = [-$row->latestDay->getTimestamp(), $row->puzzleId];

            $keys[$index] = match ($sort) {
                ComparisonSort::Recent => $recent,
                // Puzzles one of the two has not solved follow
                ComparisonSort::Lead => [$row->lead === null ? 1 : 0, $row->lead ?? 0, ...$recent],
                ComparisonSort::Lag => [$row->lead === null ? 1 : 0, -($row->lead ?? 0), ...$recent],
                ComparisonSort::Name => [$row->puzzleName === null ? 1 : 0, $row->puzzleId],
                ComparisonSort::Pieces => [$row->piecesCount, ...$recent],
                // Hardest first, not rated yet last
                ComparisonSort::Difficulty => [-($row->difficultyTier ?? -1), $row->piecesCount, ...$recent],
            };
        }

        $order = array_keys($rows);

        if ($sort === ComparisonSort::Name) {
            usort($order, static function (int $a, int $b) use ($rows, $keys): int {
                $nameA = $rows[$a]->puzzleName;
                $nameB = $rows[$b]->puzzleName;

                if ($nameA === null || $nameB === null) {
                    return $keys[$a] <=> $keys[$b];
                }

                return strnatcasecmp($nameA, $nameB) ?: $keys[$a] <=> $keys[$b];
            });
        } else {
            usort($order, static fn(int $a, int $b): int => $keys[$a] <=> $keys[$b]);
        }

        return array_map(static fn(int $index): ComparisonPuzzleRow => $rows[$index], $order);
    }

    /**
     * @param list<ComparisonSubjectRef> $refs
     * @param list<ComparisonPuzzleRow> $rows
     * @return list<ComparisonLeagueRow>
     */
    private static function league(array $refs, null|ComparisonSubjectRef $self, array $rows): array
    {
        $wins = [];
        $solved = [];
        /** @var array<string, list<float>> $ratios */
        $ratios = [];

        foreach ($refs as $ref) {
            $wins[$ref->toString()] = 0;
            $solved[$ref->toString()] = 0;
            $ratios[$ref->toString()] = [];
        }

        // One pass over the cells, not one per subject
        foreach ($rows as $row) {
            $compared = count($row->cells) >= 2;

            foreach ($row->cells as $key => $cell) {
                $solved[$key] = ($solved[$key] ?? 0) + 1;

                if ($compared) {
                    $ratios[$key][] = $cell->deltaRatio;
                }
            }

            if ($row->winner !== null) {
                $winnerKey = $row->winner->toString();
                $wins[$winnerKey] = ($wins[$winnerKey] ?? 0) + 1;
            }
        }

        $lines = [];

        foreach ($refs as $index => $ref) {
            $key = $ref->toString();
            $lines[] = [
                'index' => $index,
                'ref' => $ref,
                'wins' => $wins[$key] ?? 0,
                'solved' => $solved[$key] ?? 0,
                'compared' => count($ratios[$key] ?? []),
                'gap' => self::median($ratios[$key] ?? []),
            ];
        }

        // Most wins, then the smallest gap (none compared last), most solved, line-up order
        usort($lines, static fn(array $a, array $b): int => [
            $b['wins'],
            $a['gap'] === null,
            $a['gap'] ?? 0.0,
            $b['solved'],
            $a['index'],
        ] <=> [
            $a['wins'],
            $b['gap'] === null,
            $b['gap'] ?? 0.0,
            $a['solved'],
            $b['index'],
        ]);

        $league = [];

        foreach ($lines as $position => $line) {
            $league[] = new ComparisonLeagueRow(
                position: $position + 1,
                subject: $line['ref'],
                wins: $line['wins'],
                solved: $line['solved'],
                compared: $line['compared'],
                gap: $line['gap'],
                isSelf: $self !== null && $self->equals($line['ref']),
            );
        }

        return $league;
    }

    /**
     * @param list<ComparisonPuzzleRow> $rows
     */
    private static function headToHead(ComparisonSubjectRef $a, ComparisonSubjectRef $b, array $rows): ComparisonHeadToHead
    {
        $winsA = 0;
        $winsB = 0;
        $ties = 0;
        $logRatios = [];

        foreach ($rows as $row) {
            $cellA = $row->cell($a);
            $cellB = $row->cell($b);

            if ($cellA === null || $cellB === null) {
                continue;
            }

            match ($cellA->seconds <=> $cellB->seconds) {
                -1 => $winsA++,
                1 => $winsB++,
                0 => $ties++,
            };

            if ($cellA->seconds > 0 && $cellB->seconds > 0) {
                $logRatios[] = log($cellA->seconds / $cellB->seconds);
            }
        }

        // Median of the log ratios = geometric median of A/B: symmetric, "A is 20 % faster" ⇔ "B is 20 % slower" the same way
        $median = self::median($logRatios);
        $faster = null;
        $fasterPercent = null;

        if ($median !== null && abs($median) > 1e-9) {
            $faster = $median < 0 ? $a : $b;
            $fasterPercent = (1 - exp(-abs($median))) * 100;
        }

        return new ComparisonHeadToHead(
            a: $a,
            b: $b,
            winsA: $winsA,
            winsB: $winsB,
            ties: $ties,
            shared: $winsA + $winsB + $ties,
            faster: $faster,
            fasterPercent: $fasterPercent,
        );
    }

    /**
     * @param list<ComparisonSubjectRef> $refs
     * @param list<ComparisonPuzzleRow> $rows
     * @return array{array<string, array<string, int>>, array<string, array<string, int>>}
     */
    private static function matrix(array $refs, array $rows): array
    {
        $beats = [];
        $shared = [];

        foreach ($refs as $row) {
            foreach ($refs as $column) {
                if ($row->equals($column) === false) {
                    $beats[$row->toString()][$column->toString()] = 0;
                    $shared[$row->toString()][$column->toString()] = 0;
                }
            }
        }

        foreach ($rows as $puzzleRow) {
            foreach ($puzzleRow->cells as $rowKey => $rowCell) {
                foreach ($puzzleRow->cells as $columnKey => $columnCell) {
                    if ($rowKey === $columnKey) {
                        continue;
                    }

                    $shared[$rowKey][$columnKey]++;

                    if ($rowCell->seconds < $columnCell->seconds) {
                        $beats[$rowKey][$columnKey]++;
                    }
                }
            }
        }

        return [$beats, $shared];
    }

    /**
     * @param list<float> $values
     */
    public static function median(array $values): null|float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
