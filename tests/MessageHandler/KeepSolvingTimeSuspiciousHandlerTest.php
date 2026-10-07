<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseChanged;
use SpeedPuzzling\Web\Message\KeepSolvingTimeSuspicious;
use SpeedPuzzling\Web\Query\GetPlayerSuspiciousTimes;
use SpeedPuzzling\Web\Query\GetResultReviewEmailCandidates;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCaseRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeNoticeRepository;
use SpeedPuzzling\Web\Results\ResultReviewCandidate;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeClassifier;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeReplyAnswer;
use SpeedPuzzling\Web\Value\SuspiciousTimeResponse;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/suspicious-time-review.md, "Moderator queue": "Keep it marked" on the "Player replied" tab.
 */
final class KeepSolvingTimeSuspiciousHandlerTest extends KernelTestCase
{
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

    public function testTheReplyIsAnsweredAndTheTimeStaysMarked(): void
    {
        $notice = self::getContainer()->get(SuspiciousTimeNoticeRepository::class)->get(SuspiciousTimesFixture::NOTICE_MARKED);
        $notice->respond(SuspiciousTimeResponse::SaysCorrect, 'It is right.', $this->now());
        $this->entityManager->flush();

        $this->messageBus->dispatch(new KeepSolvingTimeSuspicious(
            caseId: SuspiciousTimesFixture::CASE_MARKED,
            decidedById: PlayerFixture::PLAYER_ADMIN,
            note: '21 minutes for 520 pieces is far beyond your other times.',
            seenFingerprint: $this->fingerprintOf(SuspiciousTimesFixture::TIME_MARKED),
        ));
        $this->entityManager->clear();

        $case = self::getContainer()->get(SuspiciousTimeCaseRepository::class)->get(SuspiciousTimesFixture::CASE_MARKED);
        self::assertSame(SuspiciousTimeCaseStatus::Marked, $case->status);
        self::assertTrue($case->time->suspicious);

        $notice = self::getContainer()->get(SuspiciousTimeNoticeRepository::class)->get(SuspiciousTimesFixture::NOTICE_MARKED);
        self::assertSame(SuspiciousTimeReplyAnswer::Kept, $notice->answer);
        self::assertSame('21 minutes for 520 pieces is far beyond your other times.', $notice->answerNote);

        $decision = $this->database->fetchAssociative(
            'SELECT decision, note FROM suspicious_time_decision WHERE time_id = :timeId',
            ['timeId' => SuspiciousTimesFixture::TIME_MARKED],
        );
        self::assertSame(['decision' => 'kept_after_reply', 'note' => '21 minutes for 520 pieces is far beyond your other times.'], $decision);
    }

    public function testAnEditTheModeratorLookedAtLeavesTheRepliedTab(): void
    {
        $case = self::getContainer()->get(SuspiciousTimeCaseRepository::class)->get(SuspiciousTimesFixture::CASE_MARKED);
        $case->playerEdited(null, SuspicionFingerprint::ofTime($case->time), SuspiciousTimeClassifier::VERSION, $this->now());
        $this->entityManager->flush();

        $this->messageBus->dispatch(new KeepSolvingTimeSuspicious(
            SuspiciousTimesFixture::CASE_MARKED,
            PlayerFixture::PLAYER_ADMIN,
            'Still far off.',
            $this->fingerprintOf(SuspiciousTimesFixture::TIME_MARKED),
        ));
        $this->entityManager->clear();

        $case = self::getContainer()->get(SuspiciousTimeCaseRepository::class)->get(SuspiciousTimesFixture::CASE_MARKED);
        self::assertSame(SuspiciousTimeCaseStatus::Marked, $case->status);
        self::assertNull($case->playerEditedAt);
    }

    public function testAnEditThatStillLookedOffIsAnsweredWithTheNote(): void
    {
        // Mia fixed the time and it still looked off (MarkedTimeEditRecheck): her notice says "fixed", the case is back
        // on the "Player replied" tab
        $case = self::getContainer()->get(SuspiciousTimeCaseRepository::class)->get(SuspiciousTimesFixture::CASE_MARKED);
        $case->playerEdited(null, SuspicionFingerprint::ofTime($case->time), SuspiciousTimeClassifier::VERSION, $this->now());
        $notice = self::getContainer()->get(SuspiciousTimeNoticeRepository::class)->get(SuspiciousTimesFixture::NOTICE_MARKED);
        $notice->respond(SuspiciousTimeResponse::Fixed, null, $this->now());
        // An address for the "Your results" e-mail
        $this->entityManager->persist(new UserAccount(Uuid::uuid7(), 'auth0|marked1', 'mia@example.com', $this->now()));
        $this->entityManager->flush();

        $this->messageBus->dispatch(new KeepSolvingTimeSuspicious(
            SuspiciousTimesFixture::CASE_MARKED,
            PlayerFixture::PLAYER_ADMIN,
            'Still four times faster than your other 520s.',
            $this->fingerprintOf(SuspiciousTimesFixture::TIME_MARKED),
        ));
        $this->entityManager->clear();

        // The note reaches her like an answer to "The time is correct": on the review page and in the next e-mail
        $notice = self::getContainer()->get(SuspiciousTimeNoticeRepository::class)->get(SuspiciousTimesFixture::NOTICE_MARKED);
        self::assertSame(SuspiciousTimeReplyAnswer::Kept, $notice->answer);
        self::assertSame('Still four times faster than your other 520s.', $notice->answerNote);
        self::assertNull($notice->answerContactId);

        $open = self::getContainer()->get(GetPlayerSuspiciousTimes::class)->openOf(SuspiciousTimesFixture::PLAYER_MARKED);
        self::assertCount(1, $open);
        self::assertSame('Still four times faster than your other 520s.', $open[0]->answerNote);

        $candidates = self::getContainer()->get(GetResultReviewEmailCandidates::class)->all();
        $mia = array_values(array_filter($candidates, static fn (ResultReviewCandidate $candidate): bool => $candidate->playerId === SuspiciousTimesFixture::PLAYER_MARKED));
        self::assertCount(1, $mia);
        self::assertSame([SuspiciousTimesFixture::NOTICE_MARKED], $mia[0]->suspiciousNoticeIds);

        // A second edit asks again - the earlier answer makes way for the next one
        $notice->respond(SuspiciousTimeResponse::Fixed, null, $this->now());
        self::assertNull($notice->answer);
        self::assertTrue($notice->awaitsAnswer());
    }

    public function testNothingToAnswerIsRefused(): void
    {
        // No reply and no edit - answered by another moderator meanwhile
        $this->assertChangedMeanwhile(new KeepSolvingTimeSuspicious(
            SuspiciousTimesFixture::CASE_MARKED,
            PlayerFixture::PLAYER_ADMIN,
            'Stays.',
            $this->fingerprintOf(SuspiciousTimesFixture::TIME_MARKED),
        ));
    }

    public function testAPendingCaseIsRefused(): void
    {
        $this->assertChangedMeanwhile(new KeepSolvingTimeSuspicious(
            SuspiciousTimesFixture::CASE_PENDING_FAST,
            PlayerFixture::PLAYER_ADMIN,
            'Stays.',
            $this->fingerprintOf(SuspiciousTimesFixture::TIME_STEADY_FAST),
        ));
    }

    public function testAnotherEntryThanTheModeratorSawIsRefused(): void
    {
        $notice = self::getContainer()->get(SuspiciousTimeNoticeRepository::class)->get(SuspiciousTimesFixture::NOTICE_MARKED);
        $notice->respond(SuspiciousTimeResponse::SaysCorrect, null, $this->now());
        $this->entityManager->flush();
        $seen = $this->fingerprintOf(SuspiciousTimesFixture::TIME_MARKED);

        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET seconds_to_solve = 4900 WHERE id = :timeId',
            ['timeId' => SuspiciousTimesFixture::TIME_MARKED],
        );
        $this->entityManager->clear();

        $this->assertChangedMeanwhile(new KeepSolvingTimeSuspicious(SuspiciousTimesFixture::CASE_MARKED, PlayerFixture::PLAYER_ADMIN, 'Stays.', $seen));

        $this->entityManager->clear();
        self::assertNull(self::getContainer()->get(SuspiciousTimeNoticeRepository::class)->get(SuspiciousTimesFixture::NOTICE_MARKED)->answer);
    }

    private function assertChangedMeanwhile(KeepSolvingTimeSuspicious $message): void
    {
        try {
            $this->messageBus->dispatch($message);
            self::fail('The decision should have been refused.');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(SuspiciousTimeCaseChanged::class, $exception->getPrevious());
        }
    }

    private function now(): \DateTimeImmutable
    {
        return self::getContainer()->get(ClockInterface::class)->now();
    }

    private function fingerprintOf(string $timeId): string
    {
        return SuspicionFingerprint::ofTime(self::getContainer()->get(PuzzleSolvingTimeRepository::class)->get($timeId));
    }
}
