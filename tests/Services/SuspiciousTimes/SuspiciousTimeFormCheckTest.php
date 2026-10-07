<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SuspiciousTimes;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeFormCheck;
use SpeedPuzzling\Web\Value\ExpectedTimeSource;
use SpeedPuzzling\Web\Value\SuspicionAssessment;
use SpeedPuzzling\Web\Value\SuspicionCheckOutcome;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use SpeedPuzzling\Web\Value\SuspiciousTimeTier;

/**
 * docs/features/suspicious-time-review.md, "Catch it while typing" - how the form's inputs become the entry the
 * classifier judges, and what a "Yes, it's right" is stored against. The form flows are in TimeVerificationFormTest.
 */
final class SuspiciousTimeFormCheckTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, null|string, int}>
     */
    public static function formGroups(): iterable
    {
        yield 'solo' => [[], 'steady1', 1];
        yield 'empty inputs' => [['', '  ', '#'], 'steady1', 1];
        yield 'a registered partner' => [['#flagged1'], 'steady1', 2];
        yield 'the same partner twice' => [['#flagged1', '#FLAGGED1 '], 'steady1', 2];
        yield 'the tracker typed in as well' => [['#Steady1', '#flagged1'], 'steady1', 2];
        yield 'a guest named like a code is somebody else' => [['steady1'], 'steady1', 2];
        yield 'a team with a guest' => [['#flagged1', '#partner1', 'Grandma'], 'steady1', 4];
        yield 'another member edits: the tracker is not among the inputs' => [['#flagged1'], null, 2];
    }

    /**
     * @param list<string> $groupPlayers
     */
    #[DataProvider('formGroups')]
    public function testThePeopleOfTheResult(array $groupPlayers, null|string $trackerCode, int $expected): void
    {
        self::assertSame($expected, SuspiciousTimeFormCheck::puzzlersCount($groupPlayers, $trackerCode));
    }

    public function testATimeNobodyAskedAboutHasNothingToConfirm(): void
    {
        self::assertNull(SuspiciousTimeFormCheck::confirmedExpectation(new SuspicionAssessment(
            outcome: SuspicionCheckOutcome::Clear,
            expectedSeconds: 30000,
            expectedSource: ExpectedTimeSource::Prediction,
        )));
    }

    public function testTheConfirmationKeepsThePlayersExpectedTime(): void
    {
        self::assertSame(34200, SuspiciousTimeFormCheck::confirmedExpectation(self::raised(
            new SuspiciousTimeReason(SuspiciousTimeReasonCode::FasterThanUsual, ['expected' => 34200, 'entered' => 9000, 'ratio' => 3.8, 'pieces' => 4000, 'source' => 'baseline']),
            expectedSeconds: 34200,
        )));
    }

    public function testWithoutAnExpectedTimeItKeepsTheCommunitysMarkTheNoticeNamed(): void
    {
        // A pair below the slow floor: the community's median time
        self::assertSame(45000, SuspiciousTimeFormCheck::confirmedExpectation(self::raised(
            new SuspiciousTimeReason(SuspiciousTimeReasonCode::BelowSlowFloor, ['ppm' => 0.33, 'floor_ppm' => 0.4, 'median' => 45000, 'entered' => 540000, 'pieces' => 3000, 'puzzling_type' => 'duo']),
        )));

        // A new player beyond the community's 99.9th percentile: the time at that pace
        self::assertSame(20000, SuspiciousTimeFormCheck::confirmedExpectation(self::raised(
            new SuspiciousTimeReason(SuspiciousTimeReasonCode::BeyondKnownPace, ['ppm' => 120.0, 'p999_ppm' => 9.0, 'entered' => 1500, 'pieces' => 3000]),
        )));
    }

    private static function raised(SuspiciousTimeReason $trigger, null|int $expectedSeconds = null): SuspicionAssessment
    {
        return new SuspicionAssessment(
            outcome: SuspicionCheckOutcome::Raised,
            tier: SuspiciousTimeTier::Strong,
            expectedSeconds: $expectedSeconds,
            expectedSource: $expectedSeconds !== null ? ExpectedTimeSource::Baseline : null,
            reasons: [$trigger],
        );
    }
}
