<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\DeletePuzzleSolvingTime;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\RecordDuplicatePrevention;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use SpeedPuzzling\Web\Value\DuplicatePreventionKind;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The detection right after a save (docs/features/duplicate-results.md, "Detection"): scoped to the result,
 * never removing anything, and never costing the player the save.
 */
final class DetectDuplicateResultsOnSaveTest extends KernelTestCase
{
    private const string DANA_USER_ID = 'auth0|twins001';

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheSameResultSavedAgainIsACaseRightAway(): void
    {
        $first = Uuid::uuid7();
        $second = Uuid::uuid7();
        $this->add($first, comment: 'First');
        $this->add($second, comment: 'Second');

        self::assertSame(
            [['player_id' => DuplicateResultsFixture::PLAYER_TWINS, 'time_a_id' => $first->toString(), 'time_b_id' => $second->toString(), 'tier' => 'strong', 'kind' => 'same_tracker', 'status' => 'open', 'detected_by' => 'save']],
            $this->casesOf($second),
        );
    }

    public function testACertainCopyIsNeverRemovedAtSaveTime(): void
    {
        // The fixtures were saved through the same events: the Tier A pair got its case, both rows stayed
        /** @var array{tier: string, status: string, detected_by: string} $case */
        $case = $this->database->fetchAssociative(
            'SELECT tier, status, detected_by FROM result_duplicate_case WHERE time_b_id = :id',
            ['id' => DuplicateResultsFixture::TIME_CERTAIN_B],
        );

        self::assertSame(['tier' => 'certain', 'status' => 'open', 'detected_by' => 'save'], $case);
        self::assertNotFalse($this->database->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE id = :id', ['id' => DuplicateResultsFixture::TIME_CERTAIN_B]));
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM result_auto_removal'));
    }

    public function testSavedAnywayAfterTheWarningIsConfirmedRealFromTheStart(): void
    {
        $first = Uuid::uuid7();
        $second = Uuid::uuid7();
        $this->add($first, comment: 'Morning');

        // What the add form records when the player chose "It's another solve, save it" (P3)
        $this->messageBus->dispatch(new RecordDuplicatePrevention(
            playerId: DuplicateResultsFixture::PLAYER_TWINS,
            kind: DuplicatePreventionKind::SavedAnyway,
            timeId: $second->toString(),
            puzzleId: DuplicateResultsFixture::PUZZLE_TWINS,
            via: SolvingTimeSource::Form,
        ));
        $this->add($second, comment: 'Evening');

        /** @var array{status: string, resolved_via: string} $case */
        $case = $this->database->fetchAssociative(
            'SELECT status, resolved_via FROM result_duplicate_case WHERE time_b_id = :id',
            ['id' => $second->toString()],
        );
        self::assertSame(['status' => 'both_real', 'resolved_via' => 'form'], $case);
    }

    public function testAnEditThatEndsTheTwinClosesItsCase(): void
    {
        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: self::DANA_USER_ID,
            puzzleSolvingTimeId: DuplicateResultsFixture::TIME_STRONG_B,
            competitionId: null,
            time: '00:40:00',
            comment: null,
            groupPlayers: [],
            finishedAt: $this->fixtureDay(41),
            finishedPuzzlesPhoto: null,
            firstAttempt: false,
            unboxed: false,
        ));

        self::assertSame('gone', $this->statusOfCaseWith(DuplicateResultsFixture::TIME_STRONG_B));
    }

    public function testDeletingACopyClosesItsCases(): void
    {
        $this->messageBus->dispatch(new DeletePuzzleSolvingTime(self::DANA_USER_ID, DuplicateResultsFixture::TIME_STRONG_B));

        self::assertSame('gone', $this->statusOfCaseWith(DuplicateResultsFixture::TIME_STRONG_B));
        // Other cases of the player stay as they were
        self::assertSame('open', $this->statusOfCaseWith(DuplicateResultsFixture::TIME_PRACTICE_B));
    }

    public function testAFailingDetectionNeverCostsTheSave(): void
    {
        $first = Uuid::uuid7();
        $second = Uuid::uuid7();
        $this->add($first, comment: 'First');

        // Rolled back with the test's transaction
        $this->database->executeStatement('ALTER TABLE result_duplicate_case RENAME TO result_duplicate_case_away');

        $this->add($second, comment: 'Second');

        self::assertNotFalse($this->database->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE id = :id', ['id' => $second->toString()]));
        self::assertFalse($this->database->fetchOne(
            'SELECT 1 FROM result_duplicate_case_away WHERE time_b_id = :id',
            ['id' => $second->toString()],
        ));
    }

    private function add(UuidInterface $timeId, null|string $comment = null): void
    {
        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: self::DANA_USER_ID,
            puzzleId: DuplicateResultsFixture::PUZZLE_TWINS,
            competitionId: null,
            time: '00:20:00',
            comment: $comment,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: new DateTimeImmutable('2025-01-15'),
            firstAttempt: false,
            unboxed: false,
            createdVia: SolvingTimeSource::Form,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function casesOf(UuidInterface $timeId): array
    {
        return $this->database->fetchAllAssociative(
            'SELECT player_id, time_a_id, time_b_id, tier, kind, status, detected_by FROM result_duplicate_case WHERE :id IN (time_a_id, time_b_id)',
            ['id' => $timeId->toString()],
        );
    }

    private function statusOfCaseWith(string $timeBId): mixed
    {
        return $this->database->fetchOne(
            'SELECT status FROM result_duplicate_case WHERE time_b_id = :id',
            ['id' => $timeBId],
        );
    }

    private function fixtureDay(int $daysAgo): DateTimeImmutable
    {
        $clock = self::getContainer()->get(ClockInterface::class);

        return $clock->now()->modify("-{$daysAgo} days")->setTime(0, 0);
    }
}
