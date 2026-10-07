<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\DetectSuspiciousTimes;
use SpeedPuzzling\Web\Message\NotifySuspiciousTimes;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCaseRepository;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeScan;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * docs/features/suspicious-time-review.md, "The notice run" and "Go-live runbook".
 */
final class NotifySuspiciousTimesHandlerTest extends KernelTestCase
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

    public function testEveryRegisteredPersonOfAMarkIsToldOnce(): void
    {
        // The scan's reconciliation gives the pair flagged by SQL a marked case
        $this->dispatch(new DetectSuspiciousTimes());

        $created = $this->notify();

        self::assertSame(2, $created);
        self::assertSame([
            [SuspiciousTimesFixture::PLAYER_FLAGGED, 'run'],
            [SuspiciousTimesFixture::PLAYER_PARTNER, 'run'],
        ], $this->noticesOf(SuspiciousTimesFixture::TIME_SQL_FLAGGED));

        // Mia was told about her mark already
        self::assertCount(1, $this->noticesOf(SuspiciousTimesFixture::TIME_MARKED));

        // A mark that stays is never told again
        self::assertSame(0, $this->notify());
    }

    public function testMarksExistingAtGoLiveAreRecordedAsToldByHand(): void
    {
        $this->dispatch(new DetectSuspiciousTimes());

        // Every registered person of every mark in force, as already told - nobody is told again
        self::assertSame(2, $this->notify(toldByHand: true));
        self::assertSame([
            [SuspiciousTimesFixture::PLAYER_FLAGGED, 'manual_email'],
            [SuspiciousTimesFixture::PLAYER_PARTNER, 'manual_email'],
        ], $this->noticesOf(SuspiciousTimesFixture::TIME_SQL_FLAGGED));
        self::assertSame(0, $this->notify());

        // ... but a new mark of the same time later is a new mark with its own notices
        $this->unmarkAndMarkAgain(SuspiciousTimesFixture::TIME_SQL_FLAGGED);

        self::assertSame(2, $this->notify());
        self::assertSame([
            [SuspiciousTimesFixture::PLAYER_FLAGGED, 'manual_email'],
            [SuspiciousTimesFixture::PLAYER_PARTNER, 'manual_email'],
            [SuspiciousTimesFixture::PLAYER_FLAGGED, 'run'],
            [SuspiciousTimesFixture::PLAYER_PARTNER, 'run'],
        ], $this->noticesOf(SuspiciousTimesFixture::TIME_SQL_FLAGGED));
    }

    public function testTheGoLiveScanRecordsItsNoticesAsToldByHand(): void
    {
        $result = self::getContainer()->get(SuspiciousTimeScan::class)->run(existingMarksToldByHand: true);

        self::assertNotNull($result->detection);
        self::assertSame(2, $result->notices);
        self::assertSame([
            [SuspiciousTimesFixture::PLAYER_FLAGGED, 'manual_email'],
            [SuspiciousTimesFixture::PLAYER_PARTNER, 'manual_email'],
        ], $this->noticesOf(SuspiciousTimesFixture::TIME_SQL_FLAGGED));

        // The next regular run tells nobody about those marks
        $next = self::getContainer()->get(SuspiciousTimeScan::class)->run();
        self::assertSame(0, $next->notices);
    }

    public function testAFailedGoLiveRunSaysToRepeatItWithTheOption(): void
    {
        // The notice run fails (its table is gone), the scan before it does not
        $this->database->executeStatement('ALTER TABLE suspicious_time_notice RENAME TO suspicious_time_notice_gone');

        $command = new CommandTester(new Application(self::$kernel ?? throw new \LogicException('No kernel'))->find('myspeedpuzzling:detect-suspicious-times'));
        $status = $command->execute(['--existing-marks-told-by-hand' => true]);

        self::assertSame(Command::FAILURE, $status);
        // Not "the next run tells the players" - a plain run would tell them about marks they were e-mailed by hand
        $output = (string) preg_replace('/\s+/', ' ', $command->getDisplay());
        self::assertStringContainsString('Run this command again with --existing-marks-told-by-hand', $output);
        self::assertStringNotContainsString('the next run tells the players', $output);
    }

    public function testNewMarkAfterAnUnmarkIsToldAgain(): void
    {
        $this->unmarkAndMarkAgain(SuspiciousTimesFixture::TIME_MARKED);

        self::assertSame(1, $this->notify());
        self::assertSame([
            [SuspiciousTimesFixture::PLAYER_MARKED, 'run'],
            [SuspiciousTimesFixture::PLAYER_MARKED, 'run'],
        ], $this->noticesOf(SuspiciousTimesFixture::TIME_MARKED));

        /** @var list<string> $markedAt */
        $markedAt = $this->database->fetchFirstColumn(
            'SELECT n.marked_at FROM suspicious_time_notice n INNER JOIN suspicious_time_case c ON c.id = n.case_id WHERE c.time_id = :id ORDER BY n.marked_at',
            ['id' => SuspiciousTimesFixture::TIME_MARKED],
        );
        self::assertCount(2, array_unique($markedAt));
    }

    public function testUnmarkedTimeIsNotTold(): void
    {
        $this->database->executeStatement('UPDATE puzzle_solving_time SET suspicious = false WHERE id = :id', ['id' => SuspiciousTimesFixture::TIME_SQL_FLAGGED]);
        $this->dispatch(new DetectSuspiciousTimes());

        $this->notify();

        self::assertSame([], $this->noticesOf(SuspiciousTimesFixture::TIME_SQL_FLAGGED));
    }

    private function unmarkAndMarkAgain(string $timeId): void
    {
        $this->entityManager->clear();
        $case = self::getContainer()->get(SuspiciousTimeCaseRepository::class)->findByTime($timeId);
        self::assertNotNull($case);
        $time = $case->time;
        $fingerprint = SuspicionFingerprint::ofTime($time);

        $time->clearSuspicion();
        $case->trust(null, $fingerprint, new DateTimeImmutable('-1 hour'));
        $this->entityManager->flush();

        $time->markSuspicious();
        $case->mark($case->reasons(), null, null, $fingerprint, new DateTimeImmutable('+1 minute'));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function notify(bool $toldByHand = false): int
    {
        $this->entityManager->clear();
        $created = $this->dispatch(new NotifySuspiciousTimes($toldByHand));
        assert(is_int($created));

        return $created;
    }

    private function dispatch(object $message): mixed
    {
        $this->entityManager->clear();

        return $this->messageBus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }

    /**
     * @return list<array{string, string}> player, via
     */
    private function noticesOf(string $timeId): array
    {
        /** @var list<array{player_id: string, via: string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT n.player_id, n.via
             FROM suspicious_time_notice n
             INNER JOIN suspicious_time_case c ON c.id = n.case_id
             WHERE c.time_id = :id
             ORDER BY n.marked_at, n.player_id',
            ['id' => $timeId],
        );

        return array_map(static fn (array $row): array => [$row['player_id'], $row['via']], $rows);
    }
}
