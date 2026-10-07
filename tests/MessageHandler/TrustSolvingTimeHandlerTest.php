<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseChanged;
use SpeedPuzzling\Web\Message\TrustSolvingTime;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCaseRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeNoticeRepository;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Tests\HoldsSuspiciousTimeCaseLock;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeReplyAnswer;
use SpeedPuzzling\Web\Value\SuspiciousTimeResponse;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/suspicious-time-review.md, "Moderator queue": "Looks fine" on a pending case and on a marked one.
 */
final class TrustSolvingTimeHandlerTest extends KernelTestCase
{
    use HoldsSuspiciousTimeCaseLock;

    private MessageBusInterface $messageBus;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAPendingCaseIsTrusted(): void
    {
        $this->messageBus->dispatch(new TrustSolvingTime(
            caseId: SuspiciousTimesFixture::CASE_PENDING_FAST,
            decidedById: PlayerFixture::PLAYER_ADMIN,
            note: null,
            seenFingerprint: $this->fingerprintOf(SuspiciousTimesFixture::TIME_STEADY_FAST),
            seenStatus: SuspiciousTimeCaseStatus::Pending,
        ));
        $this->entityManager->clear();

        $case = self::getContainer()->get(SuspiciousTimeCaseRepository::class)->get(SuspiciousTimesFixture::CASE_PENDING_FAST);
        self::assertSame(SuspiciousTimeCaseStatus::Trusted, $case->status);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $case->decidedById?->toString());
        self::assertTrue($case->isDecidedFor($this->fingerprintOf(SuspiciousTimesFixture::TIME_STEADY_FAST)));
        self::assertFalse($case->time->suspicious);
        self::assertSame(['trusted'], $this->decisionsOf(SuspiciousTimesFixture::TIME_STEADY_FAST));
    }

    public function testMarkedTimeIsUnmarkedAndThePlayersReplyAnswered(): void
    {
        $notice = self::getContainer()->get(SuspiciousTimeNoticeRepository::class)->get(SuspiciousTimesFixture::NOTICE_MARKED);
        $notice->respond(SuspiciousTimeResponse::SaysCorrect, 'I really am that fast.', self::getContainer()->get(ClockInterface::class)->now());
        $this->entityManager->flush();

        $this->messageBus->dispatch(new TrustSolvingTime(
            caseId: SuspiciousTimesFixture::CASE_MARKED,
            decidedById: PlayerFixture::PLAYER_ADMIN,
            note: 'Sorry, all good.',
            seenFingerprint: $this->fingerprintOf(SuspiciousTimesFixture::TIME_MARKED),
            seenStatus: SuspiciousTimeCaseStatus::Marked,
        ));
        $this->entityManager->clear();

        $case = self::getContainer()->get(SuspiciousTimeCaseRepository::class)->get(SuspiciousTimesFixture::CASE_MARKED);
        self::assertSame(SuspiciousTimeCaseStatus::Trusted, $case->status);
        self::assertFalse($case->time->suspicious);

        $notice = self::getContainer()->get(SuspiciousTimeNoticeRepository::class)->get(SuspiciousTimesFixture::NOTICE_MARKED);
        self::assertSame(SuspiciousTimeReplyAnswer::Trusted, $notice->answer);
        self::assertSame('Sorry, all good.', $notice->answerNote);
        self::assertNotNull($notice->answeredAt);

        self::assertSame(['unmarked'], $this->decisionsOf(SuspiciousTimesFixture::TIME_MARKED));

        // The time counts again: Silent Pier has only this result
        self::assertSame(1, $this->database->fetchOne(
            'SELECT solved_times_solo_count FROM puzzle_statistics WHERE puzzle_id = :puzzleId',
            ['puzzleId' => SuspiciousTimesFixture::PUZZLE_SILENT_PIER],
        ));
    }

    public function testALaterUnmarkReplacesAnEarlierStaysMarkedAndIsToldAgain(): void
    {
        // Mia said the time is correct, a moderator kept the mark and the answer went out in an e-mail
        $now = self::getContainer()->get(ClockInterface::class)->now();
        $notice = self::getContainer()->get(SuspiciousTimeNoticeRepository::class)->get(SuspiciousTimesFixture::NOTICE_MARKED);
        $notice->respond(SuspiciousTimeResponse::SaysCorrect, 'I really am that fast.', $now);
        $notice->answer(SuspiciousTimeReplyAnswer::Kept, 'The photo shows another box.', $now);
        $notice->answerSentInContact(Uuid::uuid7());
        $this->entityManager->flush();

        // Another moderator looks again and unmarks it
        $this->messageBus->dispatch(new TrustSolvingTime(
            caseId: SuspiciousTimesFixture::CASE_MARKED,
            decidedById: PlayerFixture::PLAYER_ADMIN,
            note: 'Checked the video - all good.',
            seenFingerprint: $this->fingerprintOf(SuspiciousTimesFixture::TIME_MARKED),
            seenStatus: SuspiciousTimeCaseStatus::Marked,
        ));
        $this->entityManager->clear();

        $notice = self::getContainer()->get(SuspiciousTimeNoticeRepository::class)->get(SuspiciousTimesFixture::NOTICE_MARKED);
        self::assertSame(SuspiciousTimeReplyAnswer::Trusted, $notice->answer);
        self::assertSame('Checked the video - all good.', $notice->answerNote);
        self::assertNull($notice->answerContactId, 'The new answer is told once more');
    }

    public function testAnEditThatStillLookedOffIsAnsweredWhenUnmarked(): void
    {
        $notice = self::getContainer()->get(SuspiciousTimeNoticeRepository::class)->get(SuspiciousTimesFixture::NOTICE_MARKED);
        $notice->respond(SuspiciousTimeResponse::Fixed, null, self::getContainer()->get(ClockInterface::class)->now());
        $this->entityManager->flush();

        $this->messageBus->dispatch(new TrustSolvingTime(
            caseId: SuspiciousTimesFixture::CASE_MARKED,
            decidedById: PlayerFixture::PLAYER_ADMIN,
            note: null,
            seenFingerprint: $this->fingerprintOf(SuspiciousTimesFixture::TIME_MARKED),
            seenStatus: SuspiciousTimeCaseStatus::Marked,
        ));
        $this->entityManager->clear();

        self::assertSame(
            SuspiciousTimeReplyAnswer::Trusted,
            self::getContainer()->get(SuspiciousTimeNoticeRepository::class)->get(SuspiciousTimesFixture::NOTICE_MARKED)->answer,
        );
    }

    public function testADecisionWaitsForTheScanOrAnEditHoldingTheCase(): void
    {
        // The scan or Mia's edit holds her case, not committed yet
        $otherRequest = $this->holdCaseInAnotherRequest($this->database, SuspiciousTimesFixture::CASE_MARKED);

        $waited = null;

        try {
            $this->messageBus->dispatch(new TrustSolvingTime(
                caseId: SuspiciousTimesFixture::CASE_MARKED,
                decidedById: PlayerFixture::PLAYER_ADMIN,
                note: null,
                seenFingerprint: $this->fingerprintOf(SuspiciousTimesFixture::TIME_MARKED),
                seenStatus: SuspiciousTimeCaseStatus::Marked,
            ));
        } catch (HandlerFailedException $exception) {
            $waited = self::statementThatWaited($exception);
        } finally {
            $otherRequest->rollBack();
        }

        // It reads the case only once the other one committed - and then sees what it wrote
        self::assertIsString($waited, 'The decision must wait for the case');
        self::assertStringStartsWith('SELECT', $waited);
        self::assertStringContainsString('FOR UPDATE', $waited);
    }

    public function testMarkedTimeWithoutAReplyIsUnmarkedWithoutAnAnswer(): void
    {
        $this->messageBus->dispatch(new TrustSolvingTime(
            caseId: SuspiciousTimesFixture::CASE_MARKED,
            decidedById: PlayerFixture::PLAYER_ADMIN,
            note: null,
            seenFingerprint: $this->fingerprintOf(SuspiciousTimesFixture::TIME_MARKED),
            seenStatus: SuspiciousTimeCaseStatus::Marked,
        ));
        $this->entityManager->clear();

        $notice = self::getContainer()->get(SuspiciousTimeNoticeRepository::class)->get(SuspiciousTimesFixture::NOTICE_MARKED);
        self::assertNull($notice->answer);
    }

    public function testAnotherStatusThanThePageShowedIsRefused(): void
    {
        // Somebody marked the case meanwhile - "Looks fine" on the pending card must not unmark it
        $this->assertChangedMeanwhile(new TrustSolvingTime(
            SuspiciousTimesFixture::CASE_MARKED,
            PlayerFixture::PLAYER_ADMIN,
            null,
            $this->fingerprintOf(SuspiciousTimesFixture::TIME_MARKED),
            SuspiciousTimeCaseStatus::Pending,
        ));

        $this->entityManager->clear();
        self::assertTrue(self::getContainer()->get(SuspiciousTimeCaseRepository::class)->get(SuspiciousTimesFixture::CASE_MARKED)->time->suspicious);
    }

    public function testAnEditedTimeIsRefused(): void
    {
        $seen = $this->fingerprintOf(SuspiciousTimesFixture::TIME_STEADY_TYPO);
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET seconds_to_solve = 2948 WHERE id = :timeId',
            ['timeId' => SuspiciousTimesFixture::TIME_STEADY_TYPO],
        );
        $this->entityManager->clear();

        $this->assertChangedMeanwhile(new TrustSolvingTime(SuspiciousTimesFixture::CASE_PENDING_SLOW, PlayerFixture::PLAYER_ADMIN, null, $seen, SuspiciousTimeCaseStatus::Pending));
        // The new entry is not what the case's reasons were about either
        $this->assertChangedMeanwhile(new TrustSolvingTime(SuspiciousTimesFixture::CASE_PENDING_SLOW, PlayerFixture::PLAYER_ADMIN, null, $this->fingerprintOf(SuspiciousTimesFixture::TIME_STEADY_TYPO), SuspiciousTimeCaseStatus::Pending));

        self::assertSame([], $this->decisionsOf(SuspiciousTimesFixture::TIME_STEADY_TYPO));
    }

    private function assertChangedMeanwhile(TrustSolvingTime $message): void
    {
        try {
            $this->messageBus->dispatch($message);
            self::fail('The decision should have been refused.');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(SuspiciousTimeCaseChanged::class, $exception->getPrevious());
        }
    }

    /**
     * @return list<string>
     */
    private function decisionsOf(string $timeId): array
    {
        /** @var list<string> $decisions */
        $decisions = $this->database->fetchFirstColumn(
            'SELECT decision FROM suspicious_time_decision WHERE time_id = :timeId ORDER BY decided_at, id',
            ['timeId' => $timeId],
        );

        return $decisions;
    }

    private function fingerprintOf(string $timeId): string
    {
        return SuspicionFingerprint::ofTime(self::getContainer()->get(PuzzleSolvingTimeRepository::class)->get($timeId));
    }
}
