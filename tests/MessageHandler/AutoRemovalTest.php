<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\AutoRemovalCanNotBeUndone;
use SpeedPuzzling\Web\Exceptions\AutoRemovalNotFound;
use SpeedPuzzling\Web\Message\AutoRemoveCertainDuplicate;
use SpeedPuzzling\Web\Message\DetectDuplicateResults;
use SpeedPuzzling\Web\Message\ReclassifyDuplicateCasesAfterRemoval;
use SpeedPuzzling\Web\Message\UndoAutoRemoval;
use SpeedPuzzling\Web\Services\DuplicateResults\DailyDuplicateDetection;
use SpeedPuzzling\Web\Tests\ClonesSolvingTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use SpeedPuzzling\Web\Value\DuplicateDetectedBy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Tier A copies are removed by the daily detection and can be brought back (docs/features/duplicate-results.md,
 * "Automatic removal"). DuplicateResultsFixture: TIME_CERTAIN_B is TIME_CERTAIN_A sent again 7 s later.
 */
final class AutoRemovalTest extends KernelTestCase
{
    use ClonesSolvingTimes;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheCopyIsRemovedAndUndoBringsBackTheVerySameRow(): void
    {
        $before = $this->row(DuplicateResultsFixture::TIME_CERTAIN_B);
        self::assertNotFalse($before);
        $solvedBefore = $this->solvedTimesCount();

        self::getContainer()->get(DailyDuplicateDetection::class)->run(DuplicateDetectedBy::Cron);

        self::assertFalse($this->row(DuplicateResultsFixture::TIME_CERTAIN_B), 'The newer copy is removed');
        self::assertNotFalse($this->row(DuplicateResultsFixture::TIME_CERTAIN_A), 'The older copy stays');
        self::assertSame($solvedBefore - 1, $this->solvedTimesCount(), 'Statistics follow the removal');

        /** @var array{status: string, resolved_via: string} $case */
        $case = $this->database->fetchAssociative(
            'SELECT status, resolved_via FROM result_duplicate_case WHERE time_b_id = :id',
            ['id' => DuplicateResultsFixture::TIME_CERTAIN_B],
        );
        self::assertSame(['status' => 'auto_removed', 'resolved_via' => 'automatic'], $case);

        /** @var array{id: string, player_id: string, kept_time_id: string, undone_at: null|string} $removal */
        $removal = $this->database->fetchAssociative(
            'SELECT id, player_id, kept_time_id, undone_at FROM result_auto_removal WHERE removed_time_id = :id',
            ['id' => DuplicateResultsFixture::TIME_CERTAIN_B],
        );
        self::assertSame(DuplicateResultsFixture::PLAYER_TWINS, $removal['player_id']);
        self::assertSame(DuplicateResultsFixture::TIME_CERTAIN_A, $removal['kept_time_id']);
        self::assertNull($removal['undone_at']);

        $this->messageBus->dispatch(new UndoAutoRemoval($removal['id'], DuplicateResultsFixture::PLAYER_TWINS));

        self::assertSame($before, $this->row(DuplicateResultsFixture::TIME_CERTAIN_B), 'The row is back exactly as it was');
        self::assertSame($solvedBefore, $this->solvedTimesCount(), 'Statistics follow the undo');
        self::assertSame('undone', $this->database->fetchOne(
            'SELECT status FROM result_duplicate_case WHERE time_b_id = :id',
            ['id' => DuplicateResultsFixture::TIME_CERTAIN_B],
        ));
        self::assertNotNull($this->database->fetchOne('SELECT undone_at FROM result_auto_removal WHERE id = :id', ['id' => $removal['id']]));

        // The pair had its case - the next detection leaves it alone
        self::getContainer()->get(DailyDuplicateDetection::class)->run(DuplicateDetectedBy::Cron);
        self::assertNotFalse($this->row(DuplicateResultsFixture::TIME_CERTAIN_B));
    }

    public function testUndoIsTheTrackersAlone(): void
    {
        $removalId = $this->removeCertainCopy();

        $this->expectException(AutoRemovalNotFound::class);

        $this->messageBus->dispatch(new UndoAutoRemoval($removalId, DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE));
    }

    public function testUndoWorksOnce(): void
    {
        $removalId = $this->removeCertainCopy();
        $this->messageBus->dispatch(new UndoAutoRemoval($removalId, DuplicateResultsFixture::PLAYER_TWINS));

        try {
            $this->messageBus->dispatch(new UndoAutoRemoval($removalId, DuplicateResultsFixture::PLAYER_TWINS));
            self::fail('A second undo must be refused');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(AutoRemovalCanNotBeUndone::class, $exception->getPrevious());
        }
    }

    public function testAPairThatChangedMeanwhileIsLeftForThePlayer(): void
    {
        $caseId = $this->database->fetchOne(
            'SELECT id FROM result_duplicate_case WHERE time_b_id = :id',
            ['id' => DuplicateResultsFixture::TIME_CERTAIN_B],
        );
        assert(is_string($caseId));

        // Edited after the detection: not identical any more
        $this->database->executeStatement(
            "UPDATE puzzle_solving_time SET comment = 'Different now' WHERE id = :id",
            ['id' => DuplicateResultsFixture::TIME_CERTAIN_B],
        );

        $envelope = $this->messageBus->dispatch(new AutoRemoveCertainDuplicate($caseId));

        self::assertFalse($envelope->last(HandledStamp::class)?->getResult());
        self::assertNotFalse($this->row(DuplicateResultsFixture::TIME_CERTAIN_B));
        self::assertSame('open', $this->database->fetchOne('SELECT status FROM result_duplicate_case WHERE id = :id', ['id' => $caseId]));
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM result_auto_removal'));
    }

    public function testOnlyCertainCasesAreRemoved(): void
    {
        $caseId = $this->database->fetchOne(
            'SELECT id FROM result_duplicate_case WHERE time_b_id = :id',
            ['id' => DuplicateResultsFixture::TIME_STRONG_B],
        );
        assert(is_string($caseId));

        $envelope = $this->messageBus->dispatch(new AutoRemoveCertainDuplicate($caseId));

        self::assertFalse($envelope->last(HandledStamp::class)?->getResult());
        self::assertNotFalse($this->row(DuplicateResultsFixture::TIME_STRONG_B));
    }

    public function testUndoFindsTheCasesByThePair(): void
    {
        $removalId = $this->removeCertainCopy();

        // The case the removal was made for is gone (e.g. cleaned up) - the undo does not need it
        $this->database->executeStatement('DELETE FROM result_duplicate_case WHERE time_b_id = :id', ['id' => DuplicateResultsFixture::TIME_CERTAIN_B]);

        $this->messageBus->dispatch(new UndoAutoRemoval($removalId, DuplicateResultsFixture::PLAYER_TWINS));

        self::assertNotFalse($this->row(DuplicateResultsFixture::TIME_CERTAIN_B));
    }

    public function testUndoIsRefusedWhenAMemberOfThePairIsNoLongerThere(): void
    {
        $removalId = $this->removeCertainCopy();

        // The removed copy was a pair result with somebody who deleted their account since
        $this->database->executeStatement(
            "UPDATE result_auto_removal SET snapshot = jsonb_set(CAST(snapshot AS jsonb), '{team}', CAST(:team AS jsonb)) WHERE id = :id",
            [
                'id' => $removalId,
                'team' => json_encode(['team_id' => null, 'puzzlers' => [
                    ['player_id' => DuplicateResultsFixture::PLAYER_TWINS, 'player_name' => null],
                    ['player_id' => Uuid::uuid7()->toString(), 'player_name' => null],
                ]], JSON_THROW_ON_ERROR),
            ],
        );

        try {
            $this->messageBus->dispatch(new UndoAutoRemoval($removalId, DuplicateResultsFixture::PLAYER_TWINS));
            self::fail('The undo must be refused');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(AutoRemovalCanNotBeUndone::class, $exception->getPrevious());
        }

        self::assertFalse($this->row(DuplicateResultsFixture::TIME_CERTAIN_B));
    }

    public function testTheOuterPairOfATripletIsCertainOnceTheMiddleCopyIsRemoved(): void
    {
        // T1 = TIME_CERTAIN_A, T2 = a copy 3 s later, T3 = TIME_CERTAIN_B (7 s after T1)
        $trackedAt = $this->database->fetchOne('SELECT tracked_at FROM puzzle_solving_time WHERE id = :id', ['id' => DuplicateResultsFixture::TIME_CERTAIN_A]);
        assert(is_string($trackedAt));
        $middle = $this->cloneSolvingTime(DuplicateResultsFixture::TIME_CERTAIN_A, [
            'tracked_at' => (new DateTimeImmutable($trackedAt))->modify('+3 seconds')->format('Y-m-d H:i:s'),
        ]);

        $this->messageBus->dispatch(new DetectDuplicateResults());

        $outerCaseId = $this->caseId(DuplicateResultsFixture::TIME_CERTAIN_A, DuplicateResultsFixture::TIME_CERTAIN_B);
        // T2 was saved in between
        self::assertSame('strong', $this->database->fetchOne('SELECT tier FROM result_duplicate_case WHERE id = :id', ['id' => $outerCaseId]));

        $firstPairCaseId = $this->caseId(DuplicateResultsFixture::TIME_CERTAIN_A, $middle);
        self::assertTrue($this->messageBus->dispatch(new AutoRemoveCertainDuplicate($firstPairCaseId))->last(HandledStamp::class)?->getResult());

        $nowCertain = $this->messageBus->dispatch(new ReclassifyDuplicateCasesAfterRemoval($firstPairCaseId))->last(HandledStamp::class)?->getResult();

        self::assertSame([$outerCaseId], $nowCertain);
        self::assertSame('certain', $this->database->fetchOne('SELECT tier FROM result_duplicate_case WHERE id = :id', ['id' => $outerCaseId]));
        self::assertSame('open', $this->database->fetchOne('SELECT status FROM result_duplicate_case WHERE id = :id', ['id' => $outerCaseId]));
    }

    private function caseId(string $timeAId, string $timeBId): string
    {
        $caseId = $this->database->fetchOne(
            'SELECT id FROM result_duplicate_case WHERE player_id = :playerId AND time_a_id = :a AND time_b_id = :b',
            ['playerId' => DuplicateResultsFixture::PLAYER_TWINS, 'a' => $timeAId, 'b' => $timeBId],
        );
        assert(is_string($caseId));

        return $caseId;
    }

    private function removeCertainCopy(): string
    {
        self::getContainer()->get(DailyDuplicateDetection::class)->run(DuplicateDetectedBy::Cron);

        $removalId = $this->database->fetchOne(
            'SELECT id FROM result_auto_removal WHERE removed_time_id = :id',
            ['id' => DuplicateResultsFixture::TIME_CERTAIN_B],
        );
        assert(is_string($removalId));

        return $removalId;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function row(string $timeId): array|false
    {
        return $this->database->fetchAssociative('SELECT * FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
    }

    private function solvedTimesCount(): int
    {
        $count = $this->database->fetchOne(
            'SELECT solved_times_count FROM puzzle_statistics WHERE puzzle_id = :id',
            ['id' => DuplicateResultsFixture::PUZZLE_TWINS],
        );
        assert(is_int($count));

        return $count;
    }
}
