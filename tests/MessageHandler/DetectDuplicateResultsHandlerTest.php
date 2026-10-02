<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\DetectDuplicateResults;
use SpeedPuzzling\Web\Results\DuplicateDetectionSummary;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use SpeedPuzzling\Web\Value\DuplicateDetectedBy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class DetectDuplicateResultsHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);

        // The fixtures come with the cases the detection at save time stored - start from none
        $this->database->executeStatement('DELETE FROM result_duplicate_case');
    }

    public function testStoresOneCasePerPersonForEveryTwin(): void
    {
        $summary = $this->detect();

        self::assertSame(5, $summary->newCases);
        self::assertSame(0, $summary->goneCases);
        // Tier A: the copy sent again 7 s later
        self::assertSame(1, $summary->autoRemoved);

        $twins = DuplicateResultsFixture::PLAYER_TWINS;
        $teammate = DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE;

        self::assertSame([
            "{$twins} same_tracker certain " . DuplicateResultsFixture::TIME_CERTAIN_A . ' ' . DuplicateResultsFixture::TIME_CERTAIN_B,
            "{$twins} same_tracker possible " . DuplicateResultsFixture::TIME_PRACTICE_A . ' ' . DuplicateResultsFixture::TIME_PRACTICE_B,
            "{$twins} same_tracker strong " . DuplicateResultsFixture::TIME_STRONG_A . ' ' . DuplicateResultsFixture::TIME_STRONG_B,
            "{$twins} teammate_copy strong " . DuplicateResultsFixture::TIME_TEAMMATE_A . ' ' . DuplicateResultsFixture::TIME_TEAMMATE_B,
            "{$teammate} teammate_copy strong " . DuplicateResultsFixture::TIME_TEAMMATE_A . ' ' . DuplicateResultsFixture::TIME_TEAMMATE_B,
        ], $this->storedCases());
    }

    public function testDifferentDaysSavedLaterIsNotACase(): void
    {
        $this->detect();

        $count = $this->database->fetchOne(
            'SELECT COUNT(*) FROM result_duplicate_case WHERE :id IN (time_a_id, time_b_id)',
            ['id' => DuplicateResultsFixture::TIME_OTHER_DAY_A],
        );

        self::assertSame(0, is_numeric($count) ? (int) $count : null);
    }

    public function testSnapshotKeepsThePair(): void
    {
        $this->detect(DuplicateDetectedBy::Backfill);

        /** @var array{status: string, detected_by: string, snapshot: string} $row */
        $row = $this->database->fetchAssociative(
            'SELECT status, detected_by, snapshot FROM result_duplicate_case WHERE player_id = :playerId AND time_a_id = :timeId',
            ['playerId' => DuplicateResultsFixture::PLAYER_TWINS, 'timeId' => DuplicateResultsFixture::TIME_TEAMMATE_A],
        );

        self::assertSame('open', $row['status']);
        self::assertSame('backfill', $row['detected_by']);

        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode($row['snapshot'], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(DuplicateResultsFixture::PUZZLE_TWINS, $snapshot['puzzle_id']);
        self::assertSame('Twins Puzzle', $snapshot['puzzle_name']);
        self::assertSame(3333, $snapshot['seconds']);
        self::assertSame(9000, $snapshot['gap_seconds']);
        // Both saved the same pair - only the tracker differs
        self::assertSame([], $snapshot['differences']);
        self::assertSame(['id' => DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE, 'name' => 'Tom Twin', 'code' => 'twins2'], $snapshot['tracker_b']);
        self::assertSame([['id' => DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE, 'name' => 'Tom Twin', 'code' => 'twins2']], $snapshot['others']);
    }

    public function testRunningAgainStoresNothingNew(): void
    {
        $this->detect();
        $summary = $this->detect();

        self::assertSame(0, $summary->newCases);
        self::assertSame(0, $summary->goneCases);
        self::assertCount(5, $this->storedCases());
    }

    public function testCaseOfDeletedCopyIsGoneAndNeverRaisedAgain(): void
    {
        $this->detect();

        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET seconds_to_solve = 2223 WHERE id = :id',
            ['id' => DuplicateResultsFixture::TIME_STRONG_B],
        );

        $summary = $this->detect();

        self::assertSame(0, $summary->newCases);
        self::assertSame(1, $summary->goneCases);
        self::assertSame('gone', $this->statusOf(DuplicateResultsFixture::TIME_STRONG_A));

        // The same time again: the pair had a case, so it is not raised a second time
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET seconds_to_solve = 2222 WHERE id = :id',
            ['id' => DuplicateResultsFixture::TIME_STRONG_B],
        );

        $summary = $this->detect();

        self::assertSame(0, $summary->newCases);
        self::assertSame('gone', $this->statusOf(DuplicateResultsFixture::TIME_STRONG_A));
    }

    public function testDecidedCaseStaysWhenItsCopyIsDeleted(): void
    {
        $this->detect();

        $this->database->executeStatement(
            "UPDATE result_duplicate_case SET status = 'both_real' WHERE time_a_id = :id",
            ['id' => DuplicateResultsFixture::TIME_CERTAIN_A],
        );
        $this->database->executeStatement(
            'DELETE FROM puzzle_solving_time WHERE id = :id',
            ['id' => DuplicateResultsFixture::TIME_CERTAIN_B],
        );

        $summary = $this->detect();

        self::assertSame(0, $summary->goneCases);
        self::assertSame('both_real', $this->statusOf(DuplicateResultsFixture::TIME_CERTAIN_A));
    }

    private function detect(DuplicateDetectedBy $detectedBy = DuplicateDetectedBy::Cron): DuplicateDetectionSummary
    {
        $envelope = $this->messageBus->dispatch(new DetectDuplicateResults($detectedBy));

        $result = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(DuplicateDetectionSummary::class, $result);

        return $result;
    }

    /**
     * @return list<string>
     */
    private function storedCases(): array
    {
        /** @var list<string> $cases */
        $cases = $this->database->fetchFirstColumn(
            "SELECT player_id || ' ' || kind || ' ' || tier || ' ' || time_a_id || ' ' || time_b_id FROM result_duplicate_case ORDER BY 1",
        );

        return $cases;
    }

    private function statusOf(string $timeAId): string
    {
        $status = $this->database->fetchOne(
            'SELECT status FROM result_duplicate_case WHERE time_a_id = :id',
            ['id' => $timeAId],
        );
        assert(is_string($status));

        return $status;
    }
}
