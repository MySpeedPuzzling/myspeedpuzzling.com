<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\AnalyticsStudio;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\RoundPuzzleSlot;
use SpeedPuzzling\Web\Results\RoundPuzzlesNow;
use SpeedPuzzling\Web\Results\VisibleRoundPuzzle;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioVisibility;

final class AnalyticsStudioVisibilityTest extends TestCase
{
    private const string SLOT_A = '018d0099-0000-0000-0000-00000000000a';
    private const string SLOT_B = '018d0099-0000-0000-0000-00000000000b';

    public function testTheHashIsMadeOfTheVisibleSlotsOnly(): void
    {
        $hiddenX = new RoundPuzzlesNow([], null, [$this->slot(self::SLOT_A, 1, null)]);
        $hiddenY = new RoundPuzzlesNow([], null, [$this->slot(self::SLOT_A, 1, null)]);

        self::assertSame(AnalyticsStudioVisibility::hash($hiddenX), AnalyticsStudioVisibility::hash($hiddenY));
        self::assertSame(16, strlen(AnalyticsStudioVisibility::hash($hiddenX)));
        self::assertSame([], AnalyticsStudioVisibility::visibleBySlot($hiddenX));
        self::assertSame([], AnalyticsStudioVisibility::visiblePuzzleIds($hiddenX));
    }

    public function testARevealAndAPictureComingOutChangeTheHash(): void
    {
        $hidden = new RoundPuzzlesNow([], null, [$this->slot(self::SLOT_A, 1, null), $this->slot(self::SLOT_B, 2, null)]);
        $nameOnly = $this->visible($this->puzzle('p1', imageHidden: true));
        $withPicture = $this->visible($this->puzzle('p1', imageHidden: false));
        $other = $this->visible($this->puzzle('p2', imageHidden: false));

        $hashes = array_map(AnalyticsStudioVisibility::hash(...), [$hidden, $nameOnly, $withPicture, $other]);

        self::assertCount(4, array_unique($hashes));
    }

    public function testVisiblePuzzlesComeInSlotOrderKeyedBySlot(): void
    {
        $puzzles = new RoundPuzzlesNow([], null, [
            $this->slot(self::SLOT_B, 1, $this->puzzle('p2', false)),
            $this->slot(self::SLOT_A, 2, null),
            $this->slot('018d0099-0000-0000-0000-00000000000c', 3, $this->puzzle('p2', false)),
        ]);

        self::assertSame([self::SLOT_B, '018d0099-0000-0000-0000-00000000000c'], array_keys(AnalyticsStudioVisibility::visibleBySlot($puzzles)));
        self::assertSame(['p2'], AnalyticsStudioVisibility::visiblePuzzleIds($puzzles));
    }

    private function visible(VisibleRoundPuzzle $puzzle): RoundPuzzlesNow
    {
        return new RoundPuzzlesNow([$puzzle], null, [$this->slot(self::SLOT_A, 1, $puzzle), $this->slot(self::SLOT_B, 2, null)]);
    }

    private function slot(string $id, int $position, null|VisibleRoundPuzzle $puzzle): RoundPuzzleSlot
    {
        return new RoundPuzzleSlot(roundPuzzleId: $id, position: $position, label: ['A', 'B', 'C'][$position - 1], revealsAt: $puzzle === null ? new DateTimeImmutable('2026-10-14 12:00:00') : null, puzzle: $puzzle);
    }

    private function puzzle(string $id, bool $imageHidden): VisibleRoundPuzzle
    {
        return new VisibleRoundPuzzle(
            roundPuzzleId: self::SLOT_A,
            puzzleId: $id,
            puzzleName: 'Puzzle ' . $id,
            piecesCount: 500,
            puzzleImage: $imageHidden ? null : 'puzzles/' . $id . '.jpg',
            puzzleImageRatio: null,
            manufacturerName: 'Brand',
            imageHidden: $imageHidden,
        );
    }
}
