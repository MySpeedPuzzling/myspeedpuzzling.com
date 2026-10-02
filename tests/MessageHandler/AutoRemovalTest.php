<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\AutoRemovalCanNotBeUndone;
use SpeedPuzzling\Web\Exceptions\AutoRemovalNotFound;
use SpeedPuzzling\Web\Message\AutoRemoveCertainDuplicate;
use SpeedPuzzling\Web\Message\DetectDuplicateResults;
use SpeedPuzzling\Web\Message\UndoAutoRemoval;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
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

        $this->messageBus->dispatch(new DetectDuplicateResults());

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
        $this->messageBus->dispatch(new DetectDuplicateResults());
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

    private function removeCertainCopy(): string
    {
        $this->messageBus->dispatch(new DetectDuplicateResults());

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
