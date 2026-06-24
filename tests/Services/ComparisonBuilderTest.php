<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use SpeedPuzzling\Web\Results\ComparisonCell;
use SpeedPuzzling\Web\Results\ComparisonPuzzleRow;
use SpeedPuzzling\Web\Results\ComparisonView;
use SpeedPuzzling\Web\Services\ComparisonBuilder;
use SpeedPuzzling\Web\Tests\DataFixtures\ComparisonFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleIntelligenceFixture;
use SpeedPuzzling\Web\Value\ComparisonFilter;
use SpeedPuzzling\Web\Value\ComparisonMode;
use SpeedPuzzling\Web\Value\ComparisonSubject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ComparisonBuilderTest extends KernelTestCase
{
    private ComparisonBuilder $builder;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->builder = self::getContainer()->get(ComparisonBuilder::class);
    }

    public function testRanksSubjectsByTimeWithDeltasAndFastestFlag(): void
    {
        $view = $this->builder->build(
            [new ComparisonSubject(ComparisonFixture::CMP_A), new ComparisonSubject(ComparisonFixture::CMP_B)],
            ComparisonMode::Solo,
            new ComparisonFilter(),
            withDifficulty: false,
            selfPlayerId: ComparisonFixture::CMP_A,
        );

        $row = $this->findRow($view, PuzzleFixture::PUZZLE_3000);
        self::assertSame(2, $row->solvedCount);
        self::assertSame(2, $row->totalSubjects);

        $cellA = $this->findCell($row, ComparisonFixture::CMP_A);
        $cellB = $this->findCell($row, ComparisonFixture::CMP_B);

        self::assertNotNull($cellA->entry);
        self::assertNotNull($cellB->entry);

        // CMP_A (600) is fastest, CMP_B (700) is +100 behind
        self::assertSame(1, $cellA->entry->rank);
        self::assertTrue($cellA->entry->isFastest);
        self::assertNull($cellA->entry->delta);

        self::assertSame(2, $cellB->entry->rank);
        self::assertFalse($cellB->entry->isFastest);
        self::assertSame(100, $cellB->entry->delta);
    }

    public function testNonSolversRenderAsEmptyCells(): void
    {
        $view = $this->builder->build(
            [
                new ComparisonSubject(ComparisonFixture::CMP_A),
                new ComparisonSubject(ComparisonFixture::CMP_B),
                new ComparisonSubject(ComparisonFixture::CMP_C),
            ],
            ComparisonMode::Solo,
            new ComparisonFilter(),
            withDifficulty: false,
            selfPlayerId: null,
        );

        // PUZZLE_4000 is solved by A and B but not C
        $row = $this->findRow($view, PuzzleFixture::PUZZLE_4000);
        self::assertSame(2, $row->solvedCount);
        self::assertSame(3, $row->totalSubjects);
        self::assertNull($this->findCell($row, ComparisonFixture::CMP_C)->entry);
    }

    public function testOnlyCommonKeepsPuzzlesSolvedByEveryone(): void
    {
        $subjects = [
            new ComparisonSubject(ComparisonFixture::CMP_A),
            new ComparisonSubject(ComparisonFixture::CMP_B),
            new ComparisonSubject(ComparisonFixture::CMP_C),
        ];

        $view = $this->builder->build(
            $subjects,
            ComparisonMode::Solo,
            new ComparisonFilter(onlyCommon: true),
            withDifficulty: false,
            selfPlayerId: null,
        );

        // Only PUZZLE_3000 is solved by all three (4000 only by A and B)
        self::assertSame([PuzzleFixture::PUZZLE_3000], array_map(static fn (ComparisonPuzzleRow $r): string => $r->puzzleId, $view->rows));
    }

    public function testFlagsSolvedTogether(): void
    {
        $view = $this->builder->build(
            [new ComparisonSubject(ComparisonFixture::CMP_A), new ComparisonSubject(ComparisonFixture::CMP_B)],
            ComparisonMode::Pairs,
            new ComparisonFilter(),
            withDifficulty: false,
            selfPlayerId: null,
        );

        // Both A and B map to the same duo record on PUZZLE_5000 -> "solved together"
        $row = $this->findRow($view, PuzzleFixture::PUZZLE_5000);
        $cellA = $this->findCell($row, ComparisonFixture::CMP_A);
        $cellB = $this->findCell($row, ComparisonFixture::CMP_B);

        self::assertNotNull($cellA->entry);
        self::assertNotNull($cellB->entry);
        self::assertTrue($cellA->entry->isShared);
        self::assertTrue($cellB->entry->isShared);
        self::assertSame($cellA->entry->fastestTimeId, $cellB->entry->fastestTimeId);
    }

    public function testManufacturerFilter(): void
    {
        $subjects = [new ComparisonSubject(ComparisonFixture::CMP_A), new ComparisonSubject(ComparisonFixture::CMP_B)];

        $unfiltered = $this->builder->build($subjects, ComparisonMode::Solo, new ComparisonFilter(), false, null);
        $brandId = $this->findRow($unfiltered, PuzzleFixture::PUZZLE_3000)->manufacturerId;

        $filtered = $this->builder->build(
            $subjects,
            ComparisonMode::Solo,
            new ComparisonFilter(manufacturerId: $brandId),
            withDifficulty: false,
            selfPlayerId: null,
        );

        self::assertNotEmpty($filtered->rows);
        foreach ($filtered->rows as $row) {
            self::assertSame($brandId, $row->manufacturerId);
        }
    }

    public function testPiecesFilter(): void
    {
        $view = $this->builder->build(
            [new ComparisonSubject(ComparisonFixture::CMP_A), new ComparisonSubject(ComparisonFixture::CMP_B)],
            ComparisonMode::Solo,
            new ComparisonFilter(pieces: 4000),
            withDifficulty: false,
            selfPlayerId: null,
        );

        self::assertSame([PuzzleFixture::PUZZLE_4000], array_map(static fn (ComparisonPuzzleRow $r): string => $r->puzzleId, $view->rows));
    }

    public function testNameFilter(): void
    {
        $view = $this->builder->build(
            [new ComparisonSubject(ComparisonFixture::CMP_A), new ComparisonSubject(ComparisonFixture::CMP_B)],
            ComparisonMode::Solo,
            new ComparisonFilter(search: 'Puzzle 16'),
            withDifficulty: false,
            selfPlayerId: null,
        );

        self::assertSame([PuzzleFixture::PUZZLE_4000], array_map(static fn (ComparisonPuzzleRow $r): string => $r->puzzleId, $view->rows));
    }

    public function testExcludesPuzzlesSolvedByOnlyOneSubject(): void
    {
        // CMP_A solved 3000 + 4000; CMP_C solved only 3000 -> 4000 has a single solver and must be dropped.
        $view = $this->builder->build(
            [new ComparisonSubject(ComparisonFixture::CMP_A), new ComparisonSubject(ComparisonFixture::CMP_C)],
            ComparisonMode::Solo,
            new ComparisonFilter(),
            withDifficulty: false,
            selfPlayerId: null,
        );

        $puzzleIds = array_map(static fn (ComparisonPuzzleRow $r): string => $r->puzzleId, $view->rows);
        self::assertContains(PuzzleFixture::PUZZLE_3000, $puzzleIds);
        self::assertNotContains(PuzzleFixture::PUZZLE_4000, $puzzleIds);
    }

    public function testDifficultyIsAttachedOnlyWhenRequested(): void
    {
        // PUZZLE_500_03 has a computed difficulty in the fixtures; REGULAR and PRIVATE both solved it solo.
        $subjects = [new ComparisonSubject(PlayerFixture::PLAYER_REGULAR), new ComparisonSubject(PlayerFixture::PLAYER_PRIVATE)];

        $withDifficulty = $this->builder->build($subjects, ComparisonMode::Solo, new ComparisonFilter(), true, null);
        self::assertNotNull($this->findRow($withDifficulty, PuzzleFixture::PUZZLE_500_03)->difficultyTier);

        $withoutDifficulty = $this->builder->build($subjects, ComparisonMode::Solo, new ComparisonFilter(), false, null);
        self::assertNull($this->findRow($withoutDifficulty, PuzzleFixture::PUZZLE_500_03)->difficultyTier);
    }

    public function testDifficultySortOrdersHardestFirst(): void
    {
        // Intel puzzles A and B both have computed (different) difficulty scores and were solved solo by REGULAR and PRIVATE.
        $view = $this->builder->build(
            [new ComparisonSubject(PlayerFixture::PLAYER_REGULAR), new ComparisonSubject(PlayerFixture::PLAYER_PRIVATE)],
            ComparisonMode::Solo,
            new ComparisonFilter(sort: 'difficulty'),
            withDifficulty: true,
            selfPlayerId: null,
        );

        $rowA = $this->findRow($view, PuzzleIntelligenceFixture::INTEL_PUZZLE_A);
        $rowB = $this->findRow($view, PuzzleIntelligenceFixture::INTEL_PUZZLE_B);
        self::assertNotNull($rowA->difficultyScore);
        self::assertNotNull($rowB->difficultyScore);

        $harderId = $rowA->difficultyScore > $rowB->difficultyScore
            ? PuzzleIntelligenceFixture::INTEL_PUZZLE_A
            : PuzzleIntelligenceFixture::INTEL_PUZZLE_B;
        $easierId = $harderId === PuzzleIntelligenceFixture::INTEL_PUZZLE_A
            ? PuzzleIntelligenceFixture::INTEL_PUZZLE_B
            : PuzzleIntelligenceFixture::INTEL_PUZZLE_A;

        $positions = array_flip(array_map(static fn (ComparisonPuzzleRow $r): string => $r->puzzleId, $view->rows));
        self::assertLessThan($positions[$easierId], $positions[$harderId], 'Harder puzzle must come before the easier one');
    }

    public function testBaselineSelectorComputesDeltaAgainstChosenSubject(): void
    {
        $baselineKey = (new ComparisonSubject(ComparisonFixture::CMP_B))->key();

        $view = $this->builder->build(
            [new ComparisonSubject(ComparisonFixture::CMP_A), new ComparisonSubject(ComparisonFixture::CMP_B)],
            ComparisonMode::Solo,
            new ComparisonFilter(baselineKey: $baselineKey),
            withDifficulty: false,
            selfPlayerId: null,
        );

        $row = $this->findRow($view, PuzzleFixture::PUZZLE_3000);
        // Baseline = CMP_B (700); CMP_A (600) is 100s faster -> -100; CMP_B is the reference -> null
        self::assertSame(-100, $this->findCell($row, ComparisonFixture::CMP_A)->entry?->delta);
        self::assertNull($this->findCell($row, ComparisonFixture::CMP_B)->entry?->delta);
    }

    public function testSelfSubjectIsFlaggedAndColoured(): void
    {
        $view = $this->builder->build(
            [new ComparisonSubject(ComparisonFixture::CMP_A), new ComparisonSubject(ComparisonFixture::CMP_B)],
            ComparisonMode::Solo,
            new ComparisonFilter(),
            withDifficulty: false,
            selfPlayerId: ComparisonFixture::CMP_A,
        );

        self::assertCount(2, $view->subjects);
        self::assertTrue($view->subjects[0]->isSelf);
        self::assertFalse($view->subjects[1]->isSelf);
        self::assertNotSame($view->subjects[0]->color, $view->subjects[1]->color);
    }

    private function findRow(ComparisonView $view, string $puzzleId): ComparisonPuzzleRow
    {
        foreach ($view->rows as $row) {
            if ($row->puzzleId === $puzzleId) {
                return $row;
            }
        }

        self::fail("Row for puzzle {$puzzleId} not found");
    }

    private function findCell(ComparisonPuzzleRow $row, string $playerId): ComparisonCell
    {
        foreach ($row->cells as $cell) {
            if ($cell->subject->player->playerId === $playerId) {
                return $cell;
            }
        }

        self::fail("Cell for player {$playerId} not found");
    }
}
