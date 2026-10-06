<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleStatus;

/**
 * The organiser's status line must be the truth - this round and the puzzle's site-wide hide together.
 */
final class RoundPuzzleStatusTest extends TestCase
{
    private const string NOW = '2026-10-20 12:00:00';

    public function testSiteWideHideEndingBeforeTheRoundIsSaidPlainly(): void
    {
        // Wisconsin right after the deploy: the round reveals at 13:15, the site-wide hide ends at 00:00
        $status = RoundPuzzleStatus::of(
            secretInRound: true,
            hideMode: PuzzleHideMode::Entirely,
            roundRevealsAt: new DateTimeImmutable('2026-10-24 13:15:00'),
            puzzleHiddenUntil: new DateTimeImmutable('2026-10-24 00:00:00'),
            puzzleImageHiddenUntil: new DateTimeImmutable('2026-10-24 00:00:00'),
            now: new DateTimeImmutable(self::NOW),
        );

        self::assertSame(RoundPuzzleStatus::HIDDEN, $status->kind);
        self::assertFalse($status->everywhere, 'Never "hidden everywhere" while the site shows it 13 hours earlier');
        self::assertEquals(new DateTimeImmutable('2026-10-24 13:15:00'), $status->until);
        self::assertEquals(new DateTimeImmutable('2026-10-24 00:00:00'), $status->elsewhereUntil);
    }

    public function testHiddenEverywhereOnlyWhenTheSiteHidesItAsLong(): void
    {
        $status = RoundPuzzleStatus::of(
            secretInRound: true,
            hideMode: PuzzleHideMode::Entirely,
            roundRevealsAt: new DateTimeImmutable('2026-10-24 13:15:00'),
            puzzleHiddenUntil: new DateTimeImmutable('2026-10-24 13:15:00'),
            puzzleImageHiddenUntil: new DateTimeImmutable('2026-10-24 13:15:00'),
            now: new DateTimeImmutable(self::NOW),
        );

        self::assertTrue($status->everywhere);
        self::assertNull($status->elsewhereUntil);
        self::assertFalse($status->heldLonger);
    }

    public function testHeldLongerByAnotherRoundOrByAnOlderDateAreToldApart(): void
    {
        $arguments = [
            'secretInRound' => true,
            'hideMode' => PuzzleHideMode::Entirely,
            'roundRevealsAt' => new DateTimeImmutable('2026-10-24 13:15:00'),
            'puzzleHiddenUntil' => new DateTimeImmutable('2026-10-25 09:00:00'),
            'puzzleImageHiddenUntil' => new DateTimeImmutable('2026-10-25 09:00:00'),
            'now' => new DateTimeImmutable(self::NOW),
        ];

        $byAnotherRound = RoundPuzzleStatus::of(...$arguments, anotherRoundHolds: true);
        self::assertTrue($byAnotherRound->heldLonger);
        self::assertTrue($byAnotherRound->heldByAnotherRound);

        // No other round holds it - a date left from before (a deleted manual round, a placeholder)
        $byAnOlderDate = RoundPuzzleStatus::of(...$arguments, anotherRoundHolds: false);
        self::assertTrue($byAnOlderDate->heldLonger);
        self::assertFalse($byAnOlderDate->heldByAnotherRound);
    }

    public function testNeverRevealedWhileThisEventPageStillHidesIt(): void
    {
        $status = RoundPuzzleStatus::of(
            secretInRound: true,
            hideMode: PuzzleHideMode::Entirely,
            roundRevealsAt: new DateTimeImmutable('2026-10-24 13:15:00'),
            puzzleHiddenUntil: null,
            puzzleImageHiddenUntil: null,
            now: new DateTimeImmutable(self::NOW),
        );

        self::assertSame(RoundPuzzleStatus::HIDDEN, $status->kind);
        self::assertFalse($status->everywhere, 'A catalogue puzzle is public elsewhere');
    }
}
