<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\ComparisonPuzzleRow;
use SpeedPuzzling\Web\Results\ComparisonResult;
use SpeedPuzzling\Web\Value\PiecesRange;

/**
 * Plain data for the five comparison charts (docs/features/player-comparison.md "Charts", members) - built from the
 * same rows as the list, no query. No Chart.js configuration here: the UI turns these arrays into charts. Subjects are
 * keyed by their ref string; `roles` says which one is the highlighted A (coral), B (indigo) or anybody else (gray).
 *
 * Puzzle names are on the rows only when the aggregate selected them (GetComparisonResults $withPuzzleNames) - ask for
 * them on the Charts tab, or labels fall back to whatever the UI shows for a nameless puzzle.
 *
 * "Pace" = how much slower (+) or faster (−) than the line-up's median time on that puzzle, in %, counted on puzzles at
 * least two subjects solved; a subject's value for a bucket / month is the median of its paces there.
 */
readonly final class ComparisonChartsData
{
    public const int LEAD_LAG_TOP = 10;

    public const int FORM_MONTHS = 12;

    /**
     * Everything at once - only on the Charts tab.
     *
     * @return array{
     *     roles: array<string, 'a'|'b'|'other'>,
     *     leadLag: array{a: null|string, b: null|string, bars: list<array{puzzleId: string, puzzleName: null|string, piecesCount: int, aSeconds: int, bSeconds: int, deltaSeconds: int}>, aheadCount: int, behindCount: int, tiedCount: int},
     *     scatter: array{a: null|string, b: null|string, points: list<array{puzzleId: string, puzzleName: null|string, piecesCount: int, aSeconds: int, bSeconds: int, winner: 'a'|'b'|'tie'}>, maxSeconds: int},
     *     pace: list<array{key: string, label: string, subjects: array<string, array{percent: float, puzzles: int}>}>,
     *     form: array{months: list<string>, series: array<string, list<null|float>>},
     *     matrix: array{subjects: list<string>, cells: array<string, array<string, array{wins: int, shared: int, share: null|float}>>, maxShared: int},
     * }
     */
    public function all(ComparisonResult $result, DateTimeImmutable $now): array
    {
        return [
            'roles' => $this->roles($result),
            'leadLag' => $this->leadLag($result),
            'scatter' => $this->scatter($result),
            'pace' => $this->paceByPieces($result),
            'form' => $this->form($result, $now),
            'matrix' => $this->matrix($result),
        ];
    }

    /**
     * Emphasis of every subject: the highlighted A coral, B indigo, everyone else gray.
     *
     * @return array<string, 'a'|'b'|'other'>
     */
    public function roles(ComparisonResult $result): array
    {
        $roles = [];

        foreach ($result->subjects as $subject) {
            $roles[$subject->toString()] = match (true) {
                $result->highlightA !== null && $subject->equals($result->highlightA) => 'a',
                $result->highlightB !== null && $subject->equals($result->highlightB) => 'b',
                default => 'other',
            };
        }

        return $roles;
    }

    /**
     * (a) Who's ahead, puzzle by puzzle: diverging bars of A − B on the puzzles both solved, from the biggest lead (most
     * negative) to the biggest lag - the top LEAD_LAG_TOP of each side, plus how many there are in total.
     *
     * @return array{a: null|string, b: null|string, bars: list<array{puzzleId: string, puzzleName: null|string, piecesCount: int, aSeconds: int, bSeconds: int, deltaSeconds: int}>, aheadCount: int, behindCount: int, tiedCount: int}
     */
    public function leadLag(ComparisonResult $result): array
    {
        $ahead = [];
        $behind = [];
        $tied = 0;

        foreach ($this->pairPoints($result) as $point) {
            $bar = [
                'puzzleId' => $point['puzzleId'],
                'puzzleName' => $point['puzzleName'],
                'piecesCount' => $point['piecesCount'],
                'aSeconds' => $point['aSeconds'],
                'bSeconds' => $point['bSeconds'],
                'deltaSeconds' => $point['aSeconds'] - $point['bSeconds'],
            ];

            if ($bar['deltaSeconds'] < 0) {
                $ahead[] = $bar;
            } elseif ($bar['deltaSeconds'] > 0) {
                $behind[] = $bar;
            } else {
                $tied++;
            }
        }

        $byDelta = static fn(array $x, array $y): int => $x['deltaSeconds'] <=> $y['deltaSeconds'] ?: $x['puzzleId'] <=> $y['puzzleId'];
        usort($ahead, $byDelta);
        usort($behind, $byDelta);

        return [
            'a' => $result->highlightA?->toString(),
            'b' => $result->highlightB?->toString(),
            'bars' => [
                ...array_slice($ahead, 0, self::LEAD_LAG_TOP),
                ...array_slice($behind, max(0, count($behind) - self::LEAD_LAG_TOP)),
            ],
            'aheadCount' => count($ahead),
            'behindCount' => count($behind),
            'tiedCount' => $tied,
        ];
    }

    /**
     * (b) A's time vs B's time on every puzzle both solved; the dashed parity line runs from 0 to maxSeconds.
     *
     * @return array{a: null|string, b: null|string, points: list<array{puzzleId: string, puzzleName: null|string, piecesCount: int, aSeconds: int, bSeconds: int, winner: 'a'|'b'|'tie'}>, maxSeconds: int}
     */
    public function scatter(ComparisonResult $result): array
    {
        $points = [];
        $max = 0;

        foreach ($this->pairPoints($result) as $point) {
            $point['winner'] = match ($point['aSeconds'] <=> $point['bSeconds']) {
                -1 => 'a',
                1 => 'b',
                default => 'tie',
            };
            $points[] = $point;
            $max = max($max, $point['aSeconds'], $point['bSeconds']);
        }

        return [
            'a' => $result->highlightA?->toString(),
            'b' => $result->highlightB?->toString(),
            'points' => $points,
            'maxSeconds' => $max,
        ];
    }

    /**
     * (c) Pace by piece count: per PiecesRange::PRESETS bucket (other counts are left out - like is compared with like)
     * and subject, the median pace. Buckets without any compared puzzle are left out.
     *
     * @return list<array{key: string, label: string, subjects: array<string, array{percent: float, puzzles: int}>}>
     */
    public function paceByPieces(ComparisonResult $result): array
    {
        $presets = [];

        foreach (PiecesRange::presets() as $preset) {
            $presets[$preset->toParam()] = $preset;
        }

        /** @var array<string, array<string, list<float>>> $paces */
        $paces = [];

        foreach ($this->paces($result) as [$row, $subjectPaces]) {
            foreach ($presets as $key => $preset) {
                if ($preset->contains($row->piecesCount)) {
                    foreach ($subjectPaces as $subject => $pace) {
                        $paces[$key][$subject][] = $pace;
                    }

                    break;
                }
            }
        }

        $buckets = [];

        foreach ($presets as $key => $preset) {
            if (isset($paces[$key]) === false) {
                continue;
            }

            $subjects = [];

            foreach ($result->subjects as $subject) {
                $values = $paces[$key][$subject->toString()] ?? [];

                if ($values !== []) {
                    $subjects[$subject->toString()] = [
                        'percent' => (float) ComparisonBuilder::median($values),
                        'puzzles' => count($values),
                    ];
                }
            }

            $buckets[] = ['key' => (string) $key, 'label' => $preset->label(), 'subjects' => $subjects];
        }

        return $buckets;
    }

    /**
     * (d) Form over time: per calendar month of the last FORM_MONTHS (oldest first, the current month last) and subject,
     * the median pace of the times solved that month; null = nothing that month.
     *
     * @return array{months: list<string>, series: array<string, list<null|float>>}
     */
    public function form(ComparisonResult $result, DateTimeImmutable $now): array
    {
        $months = [];
        $firstOfMonth = $now->modify('first day of this month')->setTime(0, 0);

        for ($i = self::FORM_MONTHS - 1; $i >= 0; $i--) {
            $months[] = $firstOfMonth->modify("-{$i} months")->format('Y-m');
        }

        $monthIndex = array_flip($months);

        /** @var array<string, array<int, list<float>>> $paces */
        $paces = [];

        foreach ($this->paces($result) as [$row, $subjectPaces]) {
            foreach ($subjectPaces as $subject => $pace) {
                $month = $row->cells[$subject]->day->format('Y-m');

                if (isset($monthIndex[$month])) {
                    $paces[$subject][$monthIndex[$month]][] = $pace;
                }
            }
        }

        $series = [];

        foreach ($result->subjects as $subject) {
            $key = $subject->toString();
            $values = [];

            foreach (array_keys($months) as $index) {
                $values[] = ComparisonBuilder::median($paces[$key][$index] ?? []);
            }

            $series[$key] = $values;
        }

        return ['months' => $months, 'series' => $series];
    }

    /**
     * (e) Head-to-head grid (3+ subjects): how often the row subject beat the column subject, of the puzzles both solved.
     * `share` = wins / shared (null when they share nothing) drives the one-hue ramp.
     *
     * @return array{subjects: list<string>, cells: array<string, array<string, array{wins: int, shared: int, share: null|float}>>, maxShared: int}
     */
    public function matrix(ComparisonResult $result): array
    {
        $cells = [];
        $maxShared = 0;

        foreach ($result->beats as $row => $columns) {
            foreach ($columns as $column => $wins) {
                $shared = $result->shared[$row][$column] ?? 0;
                $cells[$row][$column] = [
                    'wins' => $wins,
                    'shared' => $shared,
                    'share' => $shared > 0 ? (float) $wins / $shared : null,
                ];
                $maxShared = max($maxShared, $shared);
            }
        }

        return [
            'subjects' => array_map(static fn($subject): string => $subject->toString(), $result->subjects),
            'cells' => $cells,
            'maxShared' => $maxShared,
        ];
    }

    /**
     * The highlighted pair's times on the puzzles both solved, in the list's order.
     *
     * @return list<array{puzzleId: string, puzzleName: null|string, piecesCount: int, aSeconds: int, bSeconds: int}>
     */
    private function pairPoints(ComparisonResult $result): array
    {
        if ($result->highlightA === null || $result->highlightB === null) {
            return [];
        }

        $points = [];

        foreach ($result->rows as $row) {
            $cellA = $row->cell($result->highlightA);
            $cellB = $row->cell($result->highlightB);

            if ($cellA === null || $cellB === null) {
                continue;
            }

            $points[] = [
                'puzzleId' => $row->puzzleId,
                'puzzleName' => $row->puzzleName,
                'piecesCount' => $row->piecesCount,
                'aSeconds' => $cellA->seconds,
                'bSeconds' => $cellB->seconds,
            ];
        }

        return $points;
    }

    /**
     * Each subject's pace on every puzzle at least two subjects solved.
     *
     * @return list<array{ComparisonPuzzleRow, array<string, float>}>
     */
    private function paces(ComparisonResult $result): array
    {
        $paces = [];

        foreach ($result->rows as $row) {
            if ($row->solvedBy() < 2) {
                continue;
            }

            $median = ComparisonBuilder::median(array_map(
                static fn($cell): float => (float) $cell->seconds,
                array_values($row->cells),
            ));

            if ($median === null || $median <= 0) {
                continue;
            }

            $subjectPaces = [];

            foreach ($row->cells as $subject => $cell) {
                $subjectPaces[$subject] = ($cell->seconds / $median - 1) * 100;
            }

            $paces[] = [$row, $subjectPaces];
        }

        return $paces;
    }
}
