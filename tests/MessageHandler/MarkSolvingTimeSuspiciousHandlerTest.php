<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseChanged;
use SpeedPuzzling\Web\Message\MarkSolvingTimeSuspicious;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCaseRepository;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/suspicious-time-review.md, "Moderator queue": "Needs verification".
 */
final class MarkSolvingTimeSuspiciousHandlerTest extends KernelTestCase
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

    public function testThePendingTimeIsFlaggedWithTheTickedReasons(): void
    {
        $this->messageBus->dispatch(new MarkSolvingTimeSuspicious(
            caseId: SuspiciousTimesFixture::CASE_PENDING_FAST,
            decidedById: PlayerFixture::PLAYER_ADMIN,
            reasonCodes: [SuspiciousTimeReasonCode::HoursLeftOut->value],
            note: '  Please check the hours box.  ',
            seenFingerprint: $this->fingerprintOf(SuspiciousTimesFixture::TIME_STEADY_FAST),
        ));
        $this->entityManager->clear();

        $case = self::getContainer()->get(SuspiciousTimeCaseRepository::class)->get(SuspiciousTimesFixture::CASE_PENDING_FAST);
        self::assertSame(SuspiciousTimeCaseStatus::Marked, $case->status);
        // jsonb keeps the keys in its own order
        self::assertEquals([['code' => 'hours_left_out', 'params' => ['suggested' => 27000, 'hours' => 5, 'entered' => 9000]]], $case->reasonsShown);
        self::assertSame('Please check the hours box.', $case->moderatorNote);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $case->decidedById?->toString());
        self::assertNotNull($case->markedAt);
        self::assertTrue($case->time->suspicious);

        $decision = $this->database->fetchAssociative(
            'SELECT decision, case_id, reasons_shown, note, decided_by_id, decided_by_name FROM suspicious_time_decision WHERE time_id = :timeId',
            ['timeId' => SuspiciousTimesFixture::TIME_STEADY_FAST],
        );
        self::assertIsArray($decision);
        self::assertSame('marked', $decision['decision']);
        self::assertSame(SuspiciousTimesFixture::CASE_PENDING_FAST, $decision['case_id']);
        self::assertSame('Please check the hours box.', $decision['note']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $decision['decided_by_id']);
        self::assertIsString($decision['reasons_shown']);
        self::assertStringContainsString('hours_left_out', $decision['reasons_shown']);
        self::assertStringNotContainsString('faster_than_usual', $decision['reasons_shown']);

        // The statistics follow the flag right away - Harbour Lights has no other result
        self::assertSame(0, $this->database->fetchOne(
            'SELECT solved_times_solo_count FROM puzzle_statistics WHERE puzzle_id = :puzzleId',
            ['puzzleId' => SuspiciousTimesFixture::PUZZLE_HARBOUR],
        ));
    }

    public function testOnlyTheCasesOwnReasonsAPlayerMayReadAreShown(): void
    {
        $this->messageBus->dispatch(new MarkSolvingTimeSuspicious(
            caseId: SuspiciousTimesFixture::CASE_PENDING_FAST,
            decidedById: PlayerFixture::PLAYER_ADMIN,
            // new_player is a moderator hint and not a reason of this case anyway, "bogus" is no reason at all
            reasonCodes: ['faster_than_usual', 'new_player', 'bogus'],
            note: null,
            seenFingerprint: $this->fingerprintOf(SuspiciousTimesFixture::TIME_STEADY_FAST),
        ));
        $this->entityManager->clear();

        $case = self::getContainer()->get(SuspiciousTimeCaseRepository::class)->get(SuspiciousTimesFixture::CASE_PENDING_FAST);
        self::assertSame(['faster_than_usual'], array_column($case->reasonsShown, 'code'));
        self::assertNull($case->moderatorNote);
    }

    public function testACaseDecidedMeanwhileIsRefused(): void
    {
        $this->assertChangedMeanwhile(new MarkSolvingTimeSuspicious(
            caseId: SuspiciousTimesFixture::CASE_MARKED,
            decidedById: PlayerFixture::PLAYER_ADMIN,
            reasonCodes: [],
            note: null,
            seenFingerprint: $this->fingerprintOf(SuspiciousTimesFixture::TIME_MARKED),
        ));
    }

    public function testATimeEditedSinceTheScanIsRefused(): void
    {
        $seen = $this->fingerprintOf(SuspiciousTimesFixture::TIME_STEADY_FAST);
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET seconds_to_solve = 27000 WHERE id = :timeId',
            ['timeId' => SuspiciousTimesFixture::TIME_STEADY_FAST],
        );
        $this->entityManager->clear();

        // What the moderator saw ...
        $this->assertChangedMeanwhile(new MarkSolvingTimeSuspicious(SuspiciousTimesFixture::CASE_PENDING_FAST, PlayerFixture::PLAYER_ADMIN, [], null, $seen));
        // ... and the new entry alike: the case's reasons are about the old one, the next scan judges the new one
        $this->assertChangedMeanwhile(new MarkSolvingTimeSuspicious(SuspiciousTimesFixture::CASE_PENDING_FAST, PlayerFixture::PLAYER_ADMIN, [], null, $this->fingerprintOf(SuspiciousTimesFixture::TIME_STEADY_FAST)));

        $this->entityManager->clear();
        $case = self::getContainer()->get(SuspiciousTimeCaseRepository::class)->get(SuspiciousTimesFixture::CASE_PENDING_FAST);
        self::assertSame(SuspiciousTimeCaseStatus::Pending, $case->status);
        self::assertFalse($case->time->suspicious);
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM suspicious_time_decision'));
    }

    private function assertChangedMeanwhile(MarkSolvingTimeSuspicious $message): void
    {
        try {
            $this->messageBus->dispatch($message);
            self::fail('The mark should have been refused.');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(SuspiciousTimeCaseChanged::class, $exception->getPrevious());
        }
    }

    private function fingerprintOf(string $timeId): string
    {
        return SuspicionFingerprint::ofTime(self::getContainer()->get(PuzzleSolvingTimeRepository::class)->get($timeId));
    }
}
