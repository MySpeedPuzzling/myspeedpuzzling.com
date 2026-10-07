<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\SuspiciousTimeNotice;
use SpeedPuzzling\Web\Message\DetectSuspiciousTimes;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\NotifySuspiciousTimes;
use SpeedPuzzling\Web\Query\GetPlayerReviewCounts;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCaseRepository;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Results\SuspiciousTimeScanSummary;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Tests\HoldsSuspiciousTimeCaseLock;
use SpeedPuzzling\Web\Value\SuspiciousTimeNoticeVia;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * docs/features/suspicious-time-review.md, "When the player edits a marked time": an edit of the time, the puzzle or
 * the group removes the label - a changed result is another result; the scan judges the new entry like any other.
 */
final class EditMarkedTimeRecheckTest extends KernelTestCase
{
    use HoldsSuspiciousTimeCaseLock;

    private const string SAM_USER_ID = 'auth0|steady1';
    private const string MIA_USER_ID = 'auth0|marked1';
    private const string PAT_USER_ID = 'auth0|partner1';

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

    public function testAnEditOfTheTimeRemovesTheLabel(): void
    {
        $this->markSamsFastTime();
        self::assertSame(1, $this->bannerCount(SuspiciousTimesFixture::PLAYER_STEADY));

        // His usual 9.5 hours for 4000 pieces - the hours box was left empty
        $this->edit(self::SAM_USER_ID, SuspiciousTimesFixture::TIME_STEADY_FAST, '09:30:00');

        self::assertSame(34200, $this->time(SuspiciousTimesFixture::TIME_STEADY_FAST)['seconds_to_solve']);
        self::assertFalse($this->time(SuspiciousTimesFixture::TIME_STEADY_FAST)['suspicious']);

        $case = $this->case(SuspiciousTimesFixture::TIME_STEADY_FAST);
        self::assertSame('corrected', $case['status']);
        self::assertSame($this->currentFingerprint(SuspiciousTimesFixture::TIME_STEADY_FAST), $case['fingerprint']);
        self::assertSame(['fixed'], $this->responses(SuspiciousTimesFixture::TIME_STEADY_FAST));
        self::assertSame(
            [['decision' => 'unmarked_after_edit', 'decided_by_id' => null]],
            $this->database->fetchAllAssociative(
                'SELECT decision, decided_by_id FROM suspicious_time_decision WHERE time_id = :id',
                ['id' => SuspiciousTimesFixture::TIME_STEADY_FAST],
            ),
        );
        self::assertSame(0, $this->bannerCount(SuspiciousTimesFixture::PLAYER_STEADY));
    }

    public function testAnEditThatStillLooksOffRemovesTheLabelAndTheScanRaisesTheNewEntryAgain(): void
    {
        $this->markSamsFastTime();

        // Another result now - still far below his usual 9.5 hours
        $this->edit(self::SAM_USER_ID, SuspiciousTimesFixture::TIME_STEADY_FAST, '02:40:00');

        self::assertFalse($this->time(SuspiciousTimesFixture::TIME_STEADY_FAST)['suspicious']);
        self::assertSame('corrected', $this->case(SuspiciousTimesFixture::TIME_STEADY_FAST)['status']);
        self::assertSame(['fixed'], $this->responses(SuspiciousTimesFixture::TIME_STEADY_FAST));
        self::assertSame(0, $this->bannerCount(SuspiciousTimesFixture::PLAYER_STEADY));

        // The scan judges the new entry like any other result: a pending case again, never a mark by itself
        $this->entityManager->clear();
        $this->messageBus->dispatch(new DetectSuspiciousTimes());

        $case = $this->case(SuspiciousTimesFixture::TIME_STEADY_FAST);
        self::assertSame('pending', $case['status']);
        self::assertSame($this->currentFingerprint(SuspiciousTimesFixture::TIME_STEADY_FAST), $case['fingerprint']);
        self::assertStringContainsString('"entered": 9600', $case['reasons']);
        self::assertFalse($this->time(SuspiciousTimesFixture::TIME_STEADY_FAST)['suspicious']);
    }

    public function testAnEditByAMemberRemovesAMarkSetBySqlForEverybody(): void
    {
        // The pair Fay saved, flagged "by SQL": the scan gives it a marked case (origin manual), the notice run tells
        // Fay and Pat
        $this->messageBus->dispatch(new DetectSuspiciousTimes());
        $this->entityManager->clear();
        $this->messageBus->dispatch(new NotifySuspiciousTimes());
        self::assertSame(1, $this->bannerCount(SuspiciousTimesFixture::PLAYER_PARTNER));
        self::assertSame(1, $this->bannerCount(SuspiciousTimesFixture::PLAYER_FLAGGED));

        // Pat edits Fay's result
        $this->edit(self::PAT_USER_ID, SuspiciousTimesFixture::TIME_SQL_FLAGGED, '01:00:00', ['#partner1']);

        self::assertSame(3600, $this->time(SuspiciousTimesFixture::TIME_SQL_FLAGGED)['seconds_to_solve']);
        self::assertFalse($this->time(SuspiciousTimesFixture::TIME_SQL_FLAGGED)['suspicious']);

        $case = $this->case(SuspiciousTimesFixture::TIME_SQL_FLAGGED);
        self::assertSame('corrected', $case['status']);
        self::assertSame('manual', $case['origin']);

        // The label is gone for both of them
        self::assertSame(
            [SuspiciousTimesFixture::PLAYER_FLAGGED => 'fixed', SuspiciousTimesFixture::PLAYER_PARTNER => 'fixed'],
            $this->responsesByPlayer(SuspiciousTimesFixture::TIME_SQL_FLAGGED),
        );
        self::assertSame(0, $this->bannerCount(SuspiciousTimesFixture::PLAYER_PARTNER));
        self::assertSame(0, $this->bannerCount(SuspiciousTimesFixture::PLAYER_FLAGGED));
    }

    public function testAnEditRemovesAMarkWithoutReasonsToo(): void
    {
        $this->markSamsFastTime(withReasons: false);

        $this->edit(self::SAM_USER_ID, SuspiciousTimesFixture::TIME_STEADY_FAST, '09:30:00');

        self::assertFalse($this->time(SuspiciousTimesFixture::TIME_STEADY_FAST)['suspicious']);
        self::assertSame('corrected', $this->case(SuspiciousTimesFixture::TIME_STEADY_FAST)['status']);
    }

    public function testAnEditThatCannotBeJudgedRemovesTheLabel(): void
    {
        // Mia has no other results and her range no community reference here - nothing to judge, the label goes anyway
        $this->edit(self::MIA_USER_ID, SuspiciousTimesFixture::TIME_MARKED, '01:21:40');

        self::assertSame(4900, $this->time(SuspiciousTimesFixture::TIME_MARKED)['seconds_to_solve']);
        self::assertFalse($this->time(SuspiciousTimesFixture::TIME_MARKED)['suspicious']);

        $case = $this->case(SuspiciousTimesFixture::TIME_MARKED);
        self::assertSame('corrected', $case['status']);
        self::assertSame($this->currentFingerprint(SuspiciousTimesFixture::TIME_MARKED), $case['fingerprint']);
        self::assertSame(['fixed'], $this->responses(SuspiciousTimesFixture::TIME_MARKED));
    }

    public function testAnEditRemovesAFlagSetBySqlBeforeAnyScan(): void
    {
        // Flagged "by SQL", no scan yet: no case, no notices
        self::assertEquals(0, $this->database->fetchOne('SELECT COUNT(*) FROM suspicious_time_case WHERE time_id = :id', ['id' => SuspiciousTimesFixture::TIME_SQL_FLAGGED]));

        $this->edit(self::PAT_USER_ID, SuspiciousTimesFixture::TIME_SQL_FLAGGED, '01:00:00', ['#partner1']);

        self::assertFalse($this->time(SuspiciousTimesFixture::TIME_SQL_FLAGGED)['suspicious']);
        self::assertSame(
            [['decision' => 'unmarked_after_edit', 'case_id' => null]],
            $this->database->fetchAllAssociative(
                'SELECT decision, case_id FROM suspicious_time_decision WHERE time_id = :id',
                ['id' => SuspiciousTimesFixture::TIME_SQL_FLAGGED],
            ),
        );
    }

    public function testTheReCheckWaitsForADecisionHoldingTheCase(): void
    {
        // A moderator's "Looks fine" on Mia's mark is in flight and holds its case
        $otherRequest = $this->holdCaseInAnotherRequest($this->database, SuspiciousTimesFixture::CASE_MARKED);
        $before = $this->case(SuspiciousTimesFixture::TIME_MARKED);

        try {
            // Here the wait is cut short: the re-check gives up, the edit is saved anyway
            $this->edit(self::MIA_USER_ID, SuspiciousTimesFixture::TIME_MARKED, '01:21:40');
        } finally {
            $otherRequest->rollBack();
        }

        self::assertSame(4900, $this->time(SuspiciousTimesFixture::TIME_MARKED)['seconds_to_solve']);
        self::assertTrue($this->time(SuspiciousTimesFixture::TIME_MARKED)['suspicious']);
        // Nothing written over the case while somebody else held it
        self::assertSame($before, $this->case(SuspiciousTimesFixture::TIME_MARKED));
        self::assertSame([null], $this->responses(SuspiciousTimesFixture::TIME_MARKED));

        // The case is about the entry before the edit now - the next scan judges the new one
        $this->entityManager->clear();
        $summary = $this->messageBus->dispatch(new DetectSuspiciousTimes())->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(SuspiciousTimeScanSummary::class, $summary);
        self::assertSame(1, $summary->changedMarksToModerators);
        self::assertNotNull($this->case(SuspiciousTimesFixture::TIME_MARKED)['player_edited_at']);
    }

    public function testAnEditOfAnythingElseLeavesTheMarkAlone(): void
    {
        $before = $this->case(SuspiciousTimesFixture::TIME_MARKED);

        // The same time - only the comment changes
        $this->edit(self::MIA_USER_ID, SuspiciousTimesFixture::TIME_MARKED, '00:21:40', comment: 'Checked it twice');

        self::assertTrue($this->time(SuspiciousTimesFixture::TIME_MARKED)['suspicious']);
        self::assertSame($before, $this->case(SuspiciousTimesFixture::TIME_MARKED));
        self::assertSame([null], $this->responses(SuspiciousTimesFixture::TIME_MARKED));
        self::assertSame(1, $this->bannerCount(SuspiciousTimesFixture::PLAYER_MARKED));
    }

    public function testAFailingReCheckNeverCostsTheEdit(): void
    {
        $this->markSamsFastTime();
        // The re-check reads the case's notices - without the table its statement fails, inside the transaction the
        // edit is saved in
        $this->database->executeStatement('ALTER TABLE suspicious_time_notice RENAME TO suspicious_time_notice_gone');

        $this->edit(self::SAM_USER_ID, SuspiciousTimesFixture::TIME_STEADY_FAST, '09:30:00');

        // Saved anyway, still marked - the next scan finds the changed entry and judges it
        self::assertSame(34200, $this->time(SuspiciousTimesFixture::TIME_STEADY_FAST)['seconds_to_solve']);
        self::assertTrue($this->time(SuspiciousTimesFixture::TIME_STEADY_FAST)['suspicious']);
        self::assertSame('marked', $this->case(SuspiciousTimesFixture::TIME_STEADY_FAST)['status']);
    }

    /**
     * A moderator marks Sam's 2:30:00 on Harbour Lights (the pending detector case of the fixture) and the notice run
     * tells him.
     */
    private function markSamsFastTime(bool $withReasons = true): void
    {
        $this->entityManager->clear();
        $case = self::getContainer()->get(SuspiciousTimeCaseRepository::class)->findByTime(SuspiciousTimesFixture::TIME_STEADY_FAST);
        self::assertNotNull($case);
        $time = $case->time;
        $markedAt = new DateTimeImmutable('-1 day');

        $time->markSuspicious();
        $case->mark($withReasons ? $case->reasons() : [], null, Uuid::fromString(PlayerFixture::PLAYER_ADMIN), SuspicionFingerprint::ofTime($time), $markedAt);
        assert($case->markedAt !== null);
        $this->entityManager->persist(new SuspiciousTimeNotice(
            id: Uuid::uuid7(),
            case: $case,
            player: $time->player,
            markedAt: $case->markedAt,
            notifiedAt: $markedAt,
            via: SuspiciousTimeNoticeVia::Run,
        ));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * @param list<string> $groupPlayers
     */
    private function edit(string $userId, string $timeId, string $time, array $groupPlayers = [], null|string $comment = null): void
    {
        /** @var string $finishedAt */
        $finishedAt = $this->database->fetchOne('SELECT finished_at FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);

        $this->entityManager->clear();
        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: $userId,
            puzzleSolvingTimeId: $timeId,
            competitionId: null,
            time: $time,
            comment: $comment,
            groupPlayers: $groupPlayers,
            finishedAt: new DateTimeImmutable($finishedAt),
            finishedPuzzlesPhoto: null,
            firstAttempt: true,
            unboxed: false,
        ));
        $this->entityManager->clear();
    }

    /**
     * @return array{seconds_to_solve: int, suspicious: bool}
     */
    private function time(string $timeId): array
    {
        /** @var array{seconds_to_solve: int, suspicious: bool} $row */
        $row = $this->database->fetchAssociative('SELECT seconds_to_solve, suspicious FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);

        return $row;
    }

    /**
     * @return array{status: string, origin: string, fingerprint: string, player_edited_at: null|string, reasons: string, marked_at: null|string}
     */
    private function case(string $timeId): array
    {
        /** @var array{status: string, origin: string, fingerprint: string, player_edited_at: null|string, reasons: string, marked_at: null|string} $row */
        $row = $this->database->fetchAssociative(
            'SELECT status, origin, fingerprint, player_edited_at, reasons::text AS reasons, marked_at FROM suspicious_time_case WHERE time_id = :id',
            ['id' => $timeId],
        );

        return $row;
    }

    private function currentFingerprint(string $timeId): string
    {
        /** @var string $fingerprint */
        $fingerprint = $this->database->fetchOne(
            'SELECT ' . SuspicionFingerprint::sql() . ' FROM puzzle_solving_time pst INNER JOIN puzzle p ON p.id = pst.puzzle_id WHERE pst.id = :id',
            ['id' => $timeId],
        );

        return $fingerprint;
    }

    /**
     * @return list<null|string>
     */
    private function responses(string $timeId): array
    {
        return array_values($this->responsesByPlayer($timeId));
    }

    /**
     * @return array<string, null|string>
     */
    private function responsesByPlayer(string $timeId): array
    {
        /** @var array<string, null|string> $responses */
        $responses = $this->database->fetchAllKeyValue(
            'SELECT n.player_id, n.response FROM suspicious_time_notice n INNER JOIN suspicious_time_case c ON c.id = n.case_id WHERE c.time_id = :id ORDER BY n.player_id',
            ['id' => $timeId],
        );

        return $responses;
    }

    private function bannerCount(string $playerId): int
    {
        return self::getContainer()->get(GetPlayerReviewCounts::class)->forPlayer($playerId, withFirstTryConflicts: false)->suspiciousTimes;
    }
}
