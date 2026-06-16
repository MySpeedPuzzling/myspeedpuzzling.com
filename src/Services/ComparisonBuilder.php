<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetComparisonPlayers;
use SpeedPuzzling\Web\Query\GetPlayerComparisonResults;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Results\ComparisonCell;
use SpeedPuzzling\Web\Results\ComparisonEntry;
use SpeedPuzzling\Web\Results\ComparisonPuzzleRow;
use SpeedPuzzling\Web\Results\ComparisonSubjectView;
use SpeedPuzzling\Web\Results\ComparisonView;
use SpeedPuzzling\Web\Value\ComparisonFilter;
use SpeedPuzzling\Web\Value\ComparisonMode;
use SpeedPuzzling\Web\Value\ComparisonSubject;

/**
 * Assembles the full comparison view from a list of subjects: fetches each
 * subject's per-puzzle fastest/first-try results, builds per-puzzle rows with
 * ranking, deltas and the "solved together" flag, then filters and sorts.
 */
final class ComparisonBuilder
{
    /** @var list<string> */
    public const array COLORS = [
        '#fe4042', '#4e79a7', '#59a14f', '#f28e2b',
        '#9c27b0', '#00897b', '#e91e63', '#795548',
        '#3f51b5', '#8bc34a', '#ff9800', '#607d8b',
    ];

    public function __construct(
        private readonly GetPlayerComparisonResults $getPlayerComparisonResults,
        private readonly GetComparisonPlayers $getComparisonPlayers,
        private readonly GetPuzzleDifficulty $getPuzzleDifficulty,
    ) {
    }

    /**
     * @param list<ComparisonSubject> $subjects
     */
    public function build(
        array $subjects,
        ComparisonMode $mode,
        ComparisonFilter $filter,
        bool $withDifficulty,
        null|string $selfPlayerId,
    ): ComparisonView {
        $allPlayerIds = [];

        foreach ($subjects as $subject) {
            $allPlayerIds[] = $subject->playerId;

            foreach ($subject->coSolverIds as $coSolverId) {
                $allPlayerIds[] = $coSolverId;
            }
        }

        $players = $this->getComparisonPlayers->byIds(array_values(array_unique($allPlayerIds)));

        /** @var list<ComparisonSubjectView> $subjectViews */
        $subjectViews = [];
        /** @var array<string, array<string, \SpeedPuzzling\Web\Results\ComparisonResultRow>> $resultsBySubject */
        $resultsBySubject = [];
        $colorIndex = 0;

        foreach ($subjects as $subject) {
            $player = $players[$subject->playerId] ?? null;

            if ($player === null) {
                continue;
            }

            $coSolverPlayers = [];

            foreach ($subject->coSolverIds as $coSolverId) {
                if (isset($players[$coSolverId])) {
                    $coSolverPlayers[] = $players[$coSolverId];
                }
            }

            $key = $subject->key();

            $subjectViews[] = new ComparisonSubjectView(
                key: $key,
                player: $player,
                coSolvers: $coSolverPlayers,
                color: self::COLORS[$colorIndex % count(self::COLORS)],
                isSelf: $selfPlayerId !== null && $subject->playerId === $selfPlayerId,
            );

            $resultsBySubject[$key] = $this->getPlayerComparisonResults->forSubject($subject, $mode);
            $colorIndex++;
        }

        /** @var array<string, \SpeedPuzzling\Web\Results\ComparisonResultRow> $puzzleMeta */
        $puzzleMeta = [];

        foreach ($resultsBySubject as $rows) {
            foreach ($rows as $puzzleId => $row) {
                $puzzleMeta[$puzzleId] ??= $row;
            }
        }

        $difficulties = [];

        if ($withDifficulty && $puzzleMeta !== []) {
            $difficulties = $this->getPuzzleDifficulty->forPuzzleList(
                array_map(static fn (int|string $id): string => (string) $id, array_keys($puzzleMeta)),
            );
        }

        $subjectViewByKey = [];

        foreach ($subjectViews as $subjectView) {
            $subjectViewByKey[$subjectView->key] = $subjectView;
        }

        $rows = [];

        foreach ($puzzleMeta as $puzzleId => $meta) {
            $puzzleId = (string) $puzzleId;

            /** @var array<string, \SpeedPuzzling\Web\Results\ComparisonResultRow> $solverRows */
            $solverRows = [];

            foreach ($subjectViews as $subjectView) {
                if (isset($resultsBySubject[$subjectView->key][$puzzleId])) {
                    $solverRows[$subjectView->key] = $resultsBySubject[$subjectView->key][$puzzleId];
                }
            }

            $minTime = null;
            $timeIdCounts = [];

            foreach ($solverRows as $solverRow) {
                $minTime = $minTime === null ? $solverRow->fastestTime : min($minTime, $solverRow->fastestTime);
                $timeIdCounts[$solverRow->fastestTimeId] = ($timeIdCounts[$solverRow->fastestTimeId] ?? 0) + 1;
            }

            $baselineKey = ($filter->baselineKey !== '' && isset($solverRows[$filter->baselineKey]))
                ? $filter->baselineKey
                : null;
            $baselineTime = $baselineKey !== null ? $solverRows[$baselineKey]->fastestTime : $minTime;

            /** @var list<string> $orderedKeys */
            $orderedKeys = [];

            foreach ($subjectViews as $subjectView) {
                if (isset($solverRows[$subjectView->key])) {
                    $orderedKeys[] = $subjectView->key;
                }
            }

            usort(
                $orderedKeys,
                static fn (string $a, string $b): int => $solverRows[$a]->fastestTime <=> $solverRows[$b]->fastestTime,
            );

            $cells = [];

            foreach ($orderedKeys as $rank => $key) {
                $solverRow = $solverRows[$key];
                $isReference = $baselineKey !== null
                    ? $key === $baselineKey
                    : $solverRow->fastestTime === $minTime;

                $cells[] = new ComparisonCell(
                    $subjectViewByKey[$key],
                    new ComparisonEntry(
                        subjectKey: $key,
                        fastestTime: $solverRow->fastestTime,
                        fastestDate: $solverRow->fastestDate,
                        fastestTimeId: $solverRow->fastestTimeId,
                        firstTryTime: $solverRow->firstTryTime,
                        firstTryDate: $solverRow->firstTryDate,
                        rank: $rank + 1,
                        isFastest: $minTime !== null && $solverRow->fastestTime === $minTime,
                        delta: ($isReference || $baselineTime === null) ? null : $solverRow->fastestTime - $baselineTime,
                        isShared: ($timeIdCounts[$solverRow->fastestTimeId] ?? 0) > 1,
                    ),
                );
            }

            foreach ($subjectViews as $subjectView) {
                if (isset($solverRows[$subjectView->key]) === false) {
                    $cells[] = new ComparisonCell($subjectView, null);
                }
            }

            $difficulty = $difficulties[$puzzleId] ?? null;

            $rows[] = new ComparisonPuzzleRow(
                puzzleId: $puzzleId,
                puzzleName: $meta->puzzleName,
                puzzleAlternativeName: $meta->puzzleAlternativeName,
                manufacturerId: $meta->manufacturerId,
                manufacturerName: $meta->manufacturerName,
                piecesCount: $meta->piecesCount,
                puzzleImage: $meta->puzzleImage,
                difficultyTier: $difficulty?->difficultyTier,
                difficultyScore: $difficulty?->difficultyScore,
                cells: $cells,
                solvedCount: count($solverRows),
                totalSubjects: count($subjectViews),
                bestTime: $minTime ?? 0,
            );
        }

        $totalRows = count($rows);

        $manufacturers = [];
        $pieces = [];

        foreach ($rows as $row) {
            $manufacturers[$row->manufacturerId] = $row->manufacturerName;
            $pieces[$row->piecesCount] = true;
        }

        asort($manufacturers);
        $availableManufacturers = [];

        foreach ($manufacturers as $id => $name) {
            $availableManufacturers[] = ['id' => (string) $id, 'name' => $name];
        }

        $availablePieces = array_keys($pieces);
        sort($availablePieces);

        $filtered = array_values(array_filter($rows, static function (ComparisonPuzzleRow $row) use ($filter): bool {
            if ($filter->search !== '') {
                $haystack = mb_strtolower($row->puzzleName . ' ' . ($row->puzzleAlternativeName ?? ''));

                if (str_contains($haystack, mb_strtolower($filter->search)) === false) {
                    return false;
                }
            }

            if ($filter->manufacturerId !== '' && $row->manufacturerId !== $filter->manufacturerId) {
                return false;
            }

            if ($filter->pieces !== null && $row->piecesCount !== $filter->pieces) {
                return false;
            }

            if ($filter->onlyCommon && $row->isCommon() === false) {
                return false;
            }

            return true;
        }));

        usort($filtered, static function (ComparisonPuzzleRow $a, ComparisonPuzzleRow $b) use ($filter): int {
            return match ($filter->sort) {
                'pieces' => ($a->piecesCount <=> $b->piecesCount) ?: strcasecmp($a->puzzleName, $b->puzzleName),
                'fastest' => $a->bestTime <=> $b->bestTime,
                'difficulty' => (($b->difficultyScore ?? -1.0) <=> ($a->difficultyScore ?? -1.0)) ?: strcasecmp($a->puzzleName, $b->puzzleName),
                default => strcasecmp($a->puzzleName, $b->puzzleName),
            };
        });

        return new ComparisonView(
            subjects: $subjectViews,
            rows: $filtered,
            availableManufacturers: $availableManufacturers,
            availablePieces: $availablePieces,
            matchedRows: count($filtered),
            totalRows: $totalRows,
        );
    }
}
