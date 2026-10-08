<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SuspiciousTimes;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use RuntimeException;
use SpeedPuzzling\Web\Entity\SuspiciousTimeReference;
use SpeedPuzzling\Web\Query\GetPlayerPaces;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Query\GetSuspicionEntryFacts;
use SpeedPuzzling\Web\Query\GetSuspiciousTimeEvidence;
use SpeedPuzzling\Web\Query\GetSuspiciousTimeReferences;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SingleTimeSuspicionCheck;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeClassifier;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use SpeedPuzzling\Web\Value\ExpectedTimeSource;
use SpeedPuzzling\Web\Value\PaceReference;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SolveMoment;
use SpeedPuzzling\Web\Value\SuspicionCheckOutcome;
use SpeedPuzzling\Web\Value\SuspicionDirection;
use SpeedPuzzling\Web\Value\SuspicionPiecesRange;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use SpeedPuzzling\Web\Value\SuspiciousTimeTier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/features/suspicious-time-review.md, "Catch it while typing" - the scan's classifier for one entry. Built by
 * hand, to read its log.
 */
final class SingleTimeSuspicionCheckTest extends KernelTestCase
{
    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->logger = new InMemoryLogger();
    }

    public function testRaisesAnEntryFarFasterThanThePlayersBaseline(): void
    {
        // Sam's usual 9.5 hours for 4000 pieces, typed as 2:30:00 - the edited time itself left out
        $assessment = $this->check()->forEntry(
            SuspiciousTimesFixture::PLAYER_STEADY,
            SuspiciousTimesFixture::PUZZLE_HARBOUR,
            SuspiciousTimesFixture::STEADY_FAST_SECONDS,
            PuzzlingType::Solo,
            1,
            SuspiciousTimesFixture::TIME_STEADY_FAST,
            self::moment(),
        );

        self::assertNotNull($assessment);
        self::assertSame(SuspicionCheckOutcome::Raised, $assessment->outcome);
        self::assertSame(SuspicionDirection::Fast, $assessment->direction());
        self::assertSame(ExpectedTimeSource::Baseline, $assessment->expectedSource);
        self::assertEqualsWithDelta(34200, $assessment->expectedSeconds, 300);
        self::assertSame(SuspiciousTimesFixture::STEADY_FAST_SECONDS + 5 * 3600, $assessment->suggestedSeconds);
        self::assertSame(['faster_than_usual', 'hours_left_out'], $assessment->reasonCodes());
    }

    public function testPassesAnEntryInsideThePlayersOwnRange(): void
    {
        // A 3000-piece puzzle: no baseline there, his pace on the 4000s (2.5× the community) expects ~7 h
        $assessment = $this->check()->forEntry(SuspiciousTimesFixture::PLAYER_STEADY, SuspiciousTimesFixture::PUZZLE_MEADOW, 25000, PuzzlingType::Solo, 1, null, self::moment());

        self::assertNotNull($assessment);
        self::assertSame(SuspicionCheckOutcome::Clear, $assessment->outcome);
        self::assertSame(ExpectedTimeSource::Pace, $assessment->expectedSource);
        self::assertSame([], $this->logger->records);
    }

    public function testRaisesDaysCountedInsteadOfThePuzzlingTime(): void
    {
        // 49:08:00 against Sam's usual 9.5 hours for 4000 pieces: 5.2× slower and more than a day
        $assessment = $this->check()->forEntry(SuspiciousTimesFixture::PLAYER_STEADY, SuspiciousTimesFixture::PUZZLE_HARBOUR, 176880, PuzzlingType::Solo, 1, SuspiciousTimesFixture::TIME_STEADY_FAST, self::moment());

        self::assertNotNull($assessment);
        self::assertSame(SuspicionDirection::Slow, $assessment->direction());
        self::assertSame(SuspiciousTimeTier::Strong, $assessment->tier);
        self::assertSame(['slower_than_usual', 'includes_breaks'], $assessment->reasonCodes());
    }

    public function testAHardPuzzlesSlowThresholdAppliesWhileTyping(): void
    {
        // The same 49:08:00 on a puzzle a moderator gave a threshold of 8× - for its current piece count only
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement(
            'INSERT INTO suspicious_time_puzzle_confirmation (puzzle_id, pieces_count, confirmed_by_id, confirmed_at, slow_threshold) VALUES (:id, 4000, NULL, NOW(), 8.0)',
            ['id' => SuspiciousTimesFixture::PUZZLE_HARBOUR],
        );
        $entry = [SuspiciousTimesFixture::PLAYER_STEADY, SuspiciousTimesFixture::PUZZLE_HARBOUR, 176880, PuzzlingType::Solo, 1, SuspiciousTimesFixture::TIME_STEADY_FAST, self::moment()];

        $withThreshold = $this->check()->forEntry(...$entry);
        self::assertNotNull($withThreshold);
        self::assertSame(SuspicionCheckOutcome::Clear, $withThreshold->outcome);

        $database->executeStatement('UPDATE suspicious_time_puzzle_confirmation SET pieces_count = 3000');
        $lapsed = $this->check()->forEntry(...$entry);
        self::assertNotNull($lapsed);
        self::assertSame(SuspicionCheckOutcome::Raised, $lapsed->outcome);
    }

    public function testPairEntryIsJudgedByTheCommunitysSlowFloorOnly(): void
    {
        $slow = $this->check()->forEntry(SuspiciousTimesFixture::PLAYER_PARTNER, SuspiciousTimesFixture::PUZZLE_MARATHON, 540000, PuzzlingType::Duo, 2, null, self::moment());

        self::assertNotNull($slow);
        self::assertSame(['below_slow_floor', 'includes_breaks'], $slow->reasonCodes());

        $fast = $this->check()->forEntry(SuspiciousTimesFixture::PLAYER_PARTNER, SuspiciousTimesFixture::PUZZLE_MARATHON, 60, PuzzlingType::Duo, 2, null, self::moment());

        self::assertNotNull($fast);
        self::assertSame(SuspicionCheckOutcome::Clear, $fast->outcome);
    }

    public function testEarlierSolveOfThePuzzleIsTheLivePrediction(): void
    {
        // Sam solved Harbour Lights in 2:30:00 - the next solve is predicted from it
        $assessment = $this->check()->forEntry(SuspiciousTimesFixture::PLAYER_STEADY, SuspiciousTimesFixture::PUZZLE_HARBOUR, 9000, PuzzlingType::Solo, 1, null, self::moment());

        self::assertNotNull($assessment);
        self::assertSame(ExpectedTimeSource::Prediction, $assessment->expectedSource);
    }

    public function testPredictionBuiltOnAnAttemptWithASlowCaseIsNotTrusted(): void
    {
        $this->storeSoloReferenceOf500To750();

        // Sam's only Quiet Orchard attempt is the 49:08:00 with a pending slow case - the live prediction is built on it
        $honest = $this->check()->forEntry(SuspiciousTimesFixture::PLAYER_STEADY, SuspiciousTimesFixture::PUZZLE_ORCHARD, 800, PuzzlingType::Solo, 1, null, self::moment());

        // 13:20 is inside his pace for 520 pieces (~27 minutes) - judged against it, like the scan does
        self::assertNotNull($honest);
        self::assertSame(SuspicionCheckOutcome::Clear, $honest->outcome);
        self::assertSame(ExpectedTimeSource::Pace, $honest->expectedSource);

        $fast = $this->check()->forEntry(SuspiciousTimesFixture::PLAYER_STEADY, SuspiciousTimesFixture::PUZZLE_ORCHARD, 150, PuzzlingType::Solo, 1, null, self::moment());

        self::assertNotNull($fast);
        self::assertSame(ExpectedTimeSource::Pace, $fast->expectedSource);
        self::assertSame('faster_than_usual', $fast->reasonCodes()[0]);
        $reason = $fast->reason(SuspiciousTimeReasonCode::PredictionFromSlowAttempt);
        self::assertNotNull($reason);
        self::assertSame(SuspiciousTimesFixture::STEADY_TYPO_SECONDS, $reason->param('previous'));
        self::assertTrue($reason->param('raised_slow'));
    }

    public function testPredictionBuiltOnAFarTooSlowAttemptWithoutACaseIsNotTrustedEither(): void
    {
        $this->storeSoloReferenceOf500To750();
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE suspicious_time_case SET status = 'gone' WHERE id = :id",
            ['id' => SuspiciousTimesFixture::CASE_PENDING_SLOW],
        );

        // The attempt before is itself 5× his pace for the piece count
        $honest = $this->check()->forEntry(SuspiciousTimesFixture::PLAYER_STEADY, SuspiciousTimesFixture::PUZZLE_ORCHARD, 800, PuzzlingType::Solo, 1, null, self::moment());

        self::assertNotNull($honest);
        self::assertSame(SuspicionCheckOutcome::Clear, $honest->outcome);

        $fast = $this->check()->forEntry(SuspiciousTimesFixture::PLAYER_STEADY, SuspiciousTimesFixture::PUZZLE_ORCHARD, 150, PuzzlingType::Solo, 1, null, self::moment());

        self::assertNotNull($fast);
        self::assertFalse($fast->reason(SuspiciousTimeReasonCode::PredictionFromSlowAttempt)?->param('raised_slow'));
    }

    public function testTheEditedSlowAttemptItselfIsNotTheAttemptBefore(): void
    {
        $this->storeSoloReferenceOf500To750();

        // Sam fixes his 49:08:00 to 49:08 - judged without itself: no earlier attempt, so no prediction at all
        $assessment = $this->check()->forEntry(SuspiciousTimesFixture::PLAYER_STEADY, SuspiciousTimesFixture::PUZZLE_ORCHARD, 2948, PuzzlingType::Solo, 1, SuspiciousTimesFixture::TIME_STEADY_TYPO, self::moment());

        self::assertNotNull($assessment);
        self::assertSame(SuspicionCheckOutcome::Clear, $assessment->outcome);
        self::assertNotSame(ExpectedTimeSource::Prediction, $assessment->expectedSource);
    }

    public function testAnotherEditionOnlyWhenAsked(): void
    {
        // Eda's 2:40:00 on the 3000 edition, edited by a few seconds - the scan finds her 1600-piece edition for it
        $entry = [SuspiciousTimesFixture::PLAYER_EDITION, SuspiciousTimesFixture::PUZZLE_LIGHTHOUSE_3000, 9605, PuzzlingType::Solo, 1, SuspiciousTimesFixture::TIME_EDITION_FAST, self::moment()];

        $withEditions = $this->check()->forEntry(...$entry);
        self::assertNotNull($withEditions);
        self::assertTrue($withEditions->hasReason(SuspiciousTimeReasonCode::OtherEdition));

        // The add/edit form leaves the lookup out
        $withoutEditions = $this->check()->forEntry(...[...$entry, false]);
        self::assertNotNull($withoutEditions);
        self::assertTrue($withoutEditions->isRaised());
        self::assertFalse($withoutEditions->hasReason(SuspiciousTimeReasonCode::OtherEdition));

        // Never a puzzle a competition keeps secret or a hidden one
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle SET hide_until = NOW() + INTERVAL '30 days' WHERE id = :id",
            ['id' => SuspiciousTimesFixture::PUZZLE_LIGHTHOUSE_1600],
        );
        $hidden = $this->check()->forEntry(...$entry);
        self::assertNotNull($hidden);
        self::assertFalse($hidden->hasReason(SuspiciousTimeReasonCode::OtherEdition));
    }

    public function testUnknownPuzzleIsNotJudged(): void
    {
        self::assertNull($this->check()->forEntry(SuspiciousTimesFixture::PLAYER_STEADY, '018d0031-0000-0000-0000-00000000ffff', 9000, PuzzlingType::Solo, 1, null, self::moment()));
    }

    public function testFailsOpen(): void
    {
        $failingDatabase = self::createStub(Connection::class);
        $failingDatabase->method('fetchAssociative')->willThrowException(new RuntimeException('Database is gone'));

        $assessment = $this->check(new GetSuspicionEntryFacts($failingDatabase))->forEntry(
            SuspiciousTimesFixture::PLAYER_STEADY,
            SuspiciousTimesFixture::PUZZLE_HARBOUR,
            9000,
            PuzzlingType::Solo,
            1,
            null,
            self::moment(),
        );

        self::assertNull($assessment);
        self::assertTrue($this->logger->hasRecord('warning', 'the check of one entry failed'));
        self::assertInstanceOf(RuntimeException::class, $this->logger->records[0]['context']['exception'] ?? null);
    }

    private function check(null|GetSuspicionEntryFacts $entryFacts = null): SingleTimeSuspicionCheck
    {
        $database = self::getContainer()->get(Connection::class);

        return new SingleTimeSuspicionCheck(
            self::getContainer()->get(GetPlayerPrediction::class),
            $entryFacts ?? new GetSuspicionEntryFacts($database),
            new GetSuspiciousTimeReferences($database),
            new GetPlayerPaces($database),
            new GetSuspiciousTimeEvidence($database, self::getContainer()->get(ClockInterface::class)),
            new SuspiciousTimeClassifier(),
            $this->logger,
        );
    }

    /**
     * The scan computes it from every fixture; the single check reads the stored references only.
     */
    private function storeSoloReferenceOf500To750(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(SuspiciousTimeReference::of(new PaceReference(SuspicionPiecesRange::From500, PuzzlingType::Solo, 7.71, 21.08, 1000), new DateTimeImmutable()));
        $entityManager->flush();
    }

    private static function moment(): SolveMoment
    {
        return SolveMoment::of(new DateTimeImmutable('-1 day midnight'), new DateTimeImmutable());
    }
}
