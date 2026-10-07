<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SuspiciousTimeCase;
use SpeedPuzzling\Web\Entity\SuspiciousTimeNotice;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeModified;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Value\SuspicionAssessment;
use SpeedPuzzling\Web\Value\SuspicionCheckOutcome;
use SpeedPuzzling\Web\Value\SuspicionDirection;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseOrigin;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use SpeedPuzzling\Web\Value\SuspiciousTimeReplyAnswer;
use SpeedPuzzling\Web\Value\SuspiciousTimeResponse;
use SpeedPuzzling\Web\Value\SuspiciousTimeTier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The state changes of a suspicious time case and its notices (docs/features/suspicious-time-review.md), and the
 * flag on the time itself.
 */
final class SuspiciousTimeCaseTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testMarkIsStoredToTheSecondAndEndsAnEdit(): void
    {
        $case = $this->case(SuspiciousTimesFixture::CASE_PENDING_FAST);
        $case->playerEdited(null, $case->fingerprint, 1, new DateTimeImmutable());

        $case->mark($case->reasons(), '  Please check  ', Uuid::uuid7(), $case->fingerprint, new DateTimeImmutable('2026-10-07 10:00:00.654321'));

        self::assertSame(SuspiciousTimeCaseStatus::Marked, $case->status);
        self::assertSame('2026-10-07 10:00:00.000000', $case->markedAt?->format('Y-m-d H:i:s.u'));
        self::assertSame('Please check', $case->moderatorNote);
        self::assertNull($case->playerEditedAt);
        self::assertSame(['faster_than_usual', 'hours_left_out'], array_map(static fn (SuspiciousTimeReason $reason): string => $reason->code->value, $case->reasonsShown()));
    }

    public function testOnlyAPendingCaseIsRefreshedOrGone(): void
    {
        $marked = $this->case(SuspiciousTimesFixture::CASE_MARKED);
        $marked->markGone(new DateTimeImmutable());
        $marked->refreshDetection($this->slowAssessment(), 'other', 2, new DateTimeImmutable());

        self::assertSame(SuspiciousTimeCaseStatus::Marked, $marked->status);
        self::assertSame(SuspicionDirection::Fast, $marked->direction);

        $pending = $this->case(SuspiciousTimesFixture::CASE_PENDING_FAST);
        $pending->refreshDetection($this->slowAssessment(), 'other', 2, new DateTimeImmutable());

        self::assertSame(SuspicionDirection::Slow, $pending->direction);
        self::assertSame(['other', 2], [$pending->fingerprint, $pending->detectorVersion]);

        $pending->markGone(new DateTimeImmutable());
        self::assertSame(SuspiciousTimeCaseStatus::Gone, $pending->status);
    }

    public function testTrustBelongsToTheEntry(): void
    {
        $case = $this->case(SuspiciousTimesFixture::CASE_PENDING_FAST);
        $case->trust(Uuid::uuid7(), 'entry-a', new DateTimeImmutable());

        self::assertTrue($case->isDecidedFor('entry-a'));
        self::assertFalse($case->isDecidedFor('entry-b'));

        // The same entry raised again stays trusted
        $case->reopen($this->slowAssessment(), 'entry-a', 1, new DateTimeImmutable());
        self::assertSame(SuspiciousTimeCaseStatus::Trusted, $case->status);

        // Another entry: pending again, nothing of the decision carries over
        $case->reopen($this->slowAssessment(), 'entry-b', 1, new DateTimeImmutable());
        self::assertSame(SuspiciousTimeCaseStatus::Pending, $case->status);
        self::assertSame(SuspiciousTimeCaseOrigin::Detector, $case->origin);
        self::assertNull($case->decidedById);
        self::assertNull($case->markedAt);
        self::assertSame('entry-b', $case->fingerprint);
    }

    public function testMarkedCaseIsNeverReopenedButCanBeCorrected(): void
    {
        $case = $this->case(SuspiciousTimesFixture::CASE_MARKED);

        $case->reopen($this->slowAssessment(), 'entry-b', 1, new DateTimeImmutable());
        self::assertSame(SuspiciousTimeCaseStatus::Marked, $case->status);

        $case->markCorrected('entry-b', new DateTimeImmutable());
        self::assertSame(SuspiciousTimeCaseStatus::Corrected, $case->status);
        self::assertNull($case->decidedById);
    }

    public function testFlaggedOutsideTheAppIsAMarkNobodyChoseReasonsFor(): void
    {
        $time = $this->entityManager->find(PuzzleSolvingTime::class, SuspiciousTimesFixture::TIME_SQL_FLAGGED);
        self::assertNotNull($time);

        $case = SuspiciousTimeCase::flaggedOutsideTheApp(Uuid::uuid7(), $time, SuspicionFingerprint::ofTime($time), new DateTimeImmutable());

        self::assertSame([SuspiciousTimeCaseStatus::Marked, SuspiciousTimeCaseOrigin::Manual, [], null, null], [$case->status, $case->origin, $case->reasonsShown, $case->decidedById, $case->direction]);
        self::assertNotNull($case->markedAt);
    }

    public function testNoticeTakesTheTimeIsCorrectOncePerMark(): void
    {
        $notice = $this->entityManager->find(SuspiciousTimeNotice::class, SuspiciousTimesFixture::NOTICE_MARKED);
        self::assertNotNull($notice);
        self::assertTrue($notice->isAbout($notice->case));

        $notice->respond(SuspiciousTimeResponse::LeftAsIs, 'ignored', new DateTimeImmutable());
        self::assertSame([SuspiciousTimeResponse::LeftAsIs, null], [$notice->response, $notice->responseText]);

        $notice->respond(SuspiciousTimeResponse::SaysCorrect, ' I really am that fast ', new DateTimeImmutable());
        $notice->respond(SuspiciousTimeResponse::SaysCorrect, 'Second message', new DateTimeImmutable());
        self::assertSame([SuspiciousTimeResponse::SaysCorrect, 'I really am that fast'], [$notice->response, $notice->responseText]);

        $notice->answer(SuspiciousTimeReplyAnswer::Kept, 'The video shows 1:15:00', new DateTimeImmutable());
        $contact = Uuid::uuid7();
        $notice->answerSentInContact($contact);
        $notice->answerSentInContact(Uuid::uuid7());
        self::assertSame([SuspiciousTimeReplyAnswer::Kept, $contact->toString()], [$notice->answer, $notice->answerContactId?->toString()]);
    }

    public function testFlagChangesTellTheStatistics(): void
    {
        $time = $this->entityManager->find(PuzzleSolvingTime::class, SuspiciousTimesFixture::TIME_STEADY_FAST);
        self::assertNotNull($time);
        $time->popEvents();

        $time->markSuspicious();
        $time->markSuspicious();
        self::assertTrue($time->suspicious);
        self::assertCount(1, $time->popEvents(), 'Marking a marked time changes nothing');

        $time->clearSuspicion();
        self::assertFalse($time->suspicious);
        self::assertInstanceOf(PuzzleSolvingTimeModified::class, $time->popEvents()[0] ?? null);

        $time->suspicionChangedOutsideTheApp();
        self::assertFalse($time->suspicious);
        self::assertCount(1, $time->popEvents());
    }

    private function case(string $id): SuspiciousTimeCase
    {
        $case = $this->entityManager->find(SuspiciousTimeCase::class, $id);
        self::assertNotNull($case);

        return $case;
    }

    private function slowAssessment(): SuspicionAssessment
    {
        return new SuspicionAssessment(
            outcome: SuspicionCheckOutcome::Raised,
            tier: SuspiciousTimeTier::Possible,
            ratio: 0.2,
            reasons: [new SuspiciousTimeReason(SuspiciousTimeReasonCode::SlowerThanUsual, ['expected' => 600, 'entered' => 3000, 'ratio' => 5.0, 'pieces' => 520, 'source' => 'baseline'])],
            score: 5.0,
        );
    }
}
