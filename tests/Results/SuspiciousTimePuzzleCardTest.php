<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\SuspiciousTimePuzzleCard;
use SpeedPuzzling\Web\Results\SuspiciousTimePuzzleCardCase;
use SpeedPuzzling\Web\Value\ExpectedTimeSource;
use SpeedPuzzling\Web\Value\SuspicionDirection;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use SpeedPuzzling\Web\Value\SuspiciousTimeTier;

/**
 * docs/features/suspicious-time-review.md, "A hard puzzle": what a puzzle card tells the moderator choosing a threshold.
 */
final class SuspiciousTimePuzzleCardTest extends TestCase
{
    public function testEveryLineIsOffBySomethingAPairAgainstWhatMostPairsTake(): void
    {
        $own = self::line(seconds: 44100, expected: 4545);
        $pair = self::line(seconds: 51000, expected: null, reasons: [
            new SuspiciousTimeReason(SuspiciousTimeReasonCode::BelowSlowFloor, ['ppm' => 0.87, 'floor_ppm' => 1.12, 'median' => 3955, 'entered' => 51000, 'pieces' => 736, 'puzzling_type' => 'duo']),
        ]);

        self::assertEqualsWithDelta(9.7, $own->ratio(), 0.01);
        self::assertSame(4545, $own->comparedWithSeconds());
        self::assertFalse($own->isComparedWithCommunity());

        self::assertEqualsWithDelta(12.9, $pair->ratio(), 0.01);
        self::assertSame(3955, $pair->comparedWithSeconds());
        self::assertTrue($pair->isComparedWithCommunity());

        $card = self::card([$own, $pair], slowThreshold: null);
        $range = $card->ratioRange();
        self::assertNotNull($range);
        self::assertEqualsWithDelta(9.7, $range[0], 0.01);
        self::assertEqualsWithDelta(12.9, $range[1], 0.01);
        // A quarter above the slowest, rounded up
        self::assertSame(17.0, $card->suggestedSlowThreshold());
        // The one set comes first
        self::assertSame(25.0, self::card([$own, $pair], slowThreshold: 25.0)->suggestedSlowThreshold());
    }

    public function testTheSuggestionStaysWithinTheAllowedRange(): void
    {
        self::assertSame(10.0, self::card([self::line(seconds: 100, expected: null)], slowThreshold: null)->suggestedSlowThreshold());
        self::assertSame(100.0, self::card([self::line(seconds: 900000, expected: 3600)], slowThreshold: null)->suggestedSlowThreshold());
        self::assertSame(3.0, self::card([self::line(seconds: 2000, expected: 1000)], slowThreshold: null)->suggestedSlowThreshold());
    }

    /**
     * @param list<SuspiciousTimeReason> $reasons
     */
    private static function line(int $seconds, null|int $expected, array $reasons = []): SuspiciousTimePuzzleCardCase
    {
        return new SuspiciousTimePuzzleCardCase(
            caseId: '018d0031-0000-0000-0000-000000009001',
            timeId: '018d0031-0000-0000-0000-000000009002',
            playerId: '018d0031-0000-0000-0000-000000009003',
            playerName: 'Someone',
            playerCode: 'someone',
            playerPrivate: false,
            seconds: $seconds,
            puzzlersCount: $expected === null ? 2 : 1,
            expectedSeconds: $expected,
            expectedSource: $expected === null ? null : ExpectedTimeSource::Baseline,
            tier: SuspiciousTimeTier::Possible,
            reasons: $reasons,
        );
    }

    /**
     * @param list<SuspiciousTimePuzzleCardCase> $cases
     */
    private static function card(array $cases, null|float $slowThreshold): SuspiciousTimePuzzleCard
    {
        return new SuspiciousTimePuzzleCard(
            puzzleId: '018d0031-0000-0000-0000-000000009010',
            puzzleName: 'Dark Spiral',
            manufacturerName: 'Some brand',
            piecesCount: 736,
            image: null,
            difficultyScore: null,
            slowThreshold: $slowThreshold,
            playersCount: count($cases),
            soloResults: 21,
            medianSolo: 37452,
            fastestSolo: 24628,
            direction: SuspicionDirection::Slow,
            cases: $cases,
        );
    }
}
