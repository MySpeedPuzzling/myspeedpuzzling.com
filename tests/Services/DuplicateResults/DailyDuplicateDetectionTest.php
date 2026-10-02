<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DuplicateResults;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\DuplicateResults\DailyDuplicateDetection;
use SpeedPuzzling\Web\Tests\ClonesSolvingTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use SpeedPuzzling\Web\Value\DuplicateDetectedBy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The daily run of `myspeedpuzzling:detect-duplicate-results` (docs/features/duplicate-results.md, "Detection"):
 * the detection and every automatic removal in transactions of their own. DuplicateResultsFixture: TIME_CERTAIN_B is
 * TIME_CERTAIN_A sent again 7 s later.
 */
final class DailyDuplicateDetectionTest extends KernelTestCase
{
    use ClonesSolvingTimes;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testOneFailingRemovalNeitherUndoesTheDetectionNorTheOtherRemovals(): void
    {
        // A second certain pair: TIME_STRONG_A sent again 5 s later
        $copy = $this->resentAfter(DuplicateResultsFixture::TIME_STRONG_A, 5);

        // The removal of TIME_CERTAIN_B fails at its flush (rolled back with the test's transaction)
        $this->database->executeStatement(<<<SQL
CREATE FUNCTION refuse_delete_in_test() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'refused by the test'; END $$
SQL);
        $this->database->executeStatement(sprintf(
            "CREATE TRIGGER refuse_delete_in_test BEFORE DELETE ON puzzle_solving_time FOR EACH ROW WHEN (OLD.id = '%s') EXECUTE FUNCTION refuse_delete_in_test()",
            DuplicateResultsFixture::TIME_CERTAIN_B,
        ));

        $summary = self::getContainer()->get(DailyDuplicateDetection::class)->run(DuplicateDetectedBy::Cron);

        self::assertSame(1, $summary->autoRemovalsFailed);
        self::assertSame(1, $summary->autoRemoved);

        // The failed one stays for the next run
        self::assertNotFalse($this->row(DuplicateResultsFixture::TIME_CERTAIN_B));
        self::assertSame('open', $this->database->fetchOne(
            'SELECT status FROM result_duplicate_case WHERE time_b_id = :id',
            ['id' => DuplicateResultsFixture::TIME_CERTAIN_B],
        ));
        self::assertFalse($this->database->fetchOne(
            'SELECT 1 FROM result_auto_removal WHERE removed_time_id = :id',
            ['id' => DuplicateResultsFixture::TIME_CERTAIN_B],
        ));

        // The detection was stored and the other copy removed
        self::assertFalse($this->row($copy));
        self::assertNotFalse($this->row(DuplicateResultsFixture::TIME_STRONG_A));
        self::assertSame('auto_removed', $this->database->fetchOne(
            'SELECT status FROM result_duplicate_case WHERE time_a_id = :kept AND time_b_id = :copy',
            ['kept' => DuplicateResultsFixture::TIME_STRONG_A, 'copy' => $copy],
        ));
    }

    public function testTheSameResultSentThreeTimesKeepsOnlyTheFirst(): void
    {
        // T1 = TIME_CERTAIN_A, T2 = a copy 3 s later, T3 = TIME_CERTAIN_B (7 s after T1): (T1, T3) has T2 in between
        $middle = $this->resentAfter(DuplicateResultsFixture::TIME_CERTAIN_A, 3);

        $summary = self::getContainer()->get(DailyDuplicateDetection::class)->run(DuplicateDetectedBy::Cron);

        self::assertSame(0, $summary->autoRemovalsFailed);
        self::assertSame(2, $summary->autoRemoved, 'Both copies go on the same run');
        self::assertNotFalse($this->row(DuplicateResultsFixture::TIME_CERTAIN_A));
        self::assertFalse($this->row($middle));
        self::assertFalse($this->row(DuplicateResultsFixture::TIME_CERTAIN_B));

        self::assertSame(0, $this->database->fetchOne(
            "SELECT COUNT(*) FROM result_duplicate_case WHERE status = 'open' AND :id IN (time_a_id, time_b_id)",
            ['id' => DuplicateResultsFixture::TIME_CERTAIN_A],
        ));
        self::assertEqualsCanonicalizing(
            [$middle, DuplicateResultsFixture::TIME_CERTAIN_B],
            $this->database->fetchFirstColumn('SELECT CAST(removed_time_id AS text) FROM result_auto_removal'),
        );
    }

    private function resentAfter(string $timeId, int $seconds): string
    {
        $trackedAt = $this->database->fetchOne('SELECT tracked_at FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        assert(is_string($trackedAt));

        return $this->cloneSolvingTime($timeId, [
            'tracked_at' => (new DateTimeImmutable($trackedAt))->modify("+{$seconds} seconds")->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, mixed>|false
     */
    private function row(string $timeId): array|false
    {
        return $this->database->fetchAssociative('SELECT * FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
    }
}
