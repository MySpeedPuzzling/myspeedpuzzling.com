<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\SuspiciousTimePuzzleConfirmation;
use SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseChanged;
use SpeedPuzzling\Web\Message\SetPuzzleSlowThreshold;
use SpeedPuzzling\Web\Repository\SuspiciousTimePuzzleConfirmationRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/suspicious-time-review.md, "A hard puzzle": a moderator's slow threshold for a puzzle.
 */
final class SetPuzzleSlowThresholdHandlerTest extends KernelTestCase
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

    public function testTheThresholdIsSavedForThePiecesCountAndLogged(): void
    {
        $this->set(PlayerFixture::PLAYER_ADMIN, 4000, 12.5);

        $confirmation = $this->confirmation();
        self::assertNotNull($confirmation);
        self::assertSame([4000, 12.5, PlayerFixture::PLAYER_ADMIN], [$confirmation->piecesCount, $confirmation->slowThreshold, $confirmation->confirmedById?->toString()]);

        self::assertSame([[
            'decision' => 'slow_threshold_set',
            'time_id' => null,
            'decided_by_id' => PlayerFixture::PLAYER_ADMIN,
            'slow_threshold' => '12.5',
            'previous' => null,
        ]], $this->decisions());
    }

    public function testTheThresholdIsChangedAndRemoved(): void
    {
        $this->set(PlayerFixture::PLAYER_ADMIN, 4000, 12.0);
        $this->set(PlayerFixture::PLAYER_REGULAR, 4000, 20.0);

        $changed = $this->confirmation();
        self::assertNotNull($changed);
        self::assertSame([20.0, PlayerFixture::PLAYER_REGULAR], [$changed->slowThreshold, $changed->confirmedById?->toString()]);

        $this->set(PlayerFixture::PLAYER_ADMIN, 4000, null);

        self::assertNull($this->confirmation()?->slowThreshold);
        self::assertSame(
            [['slow_threshold_set', '12.0', null], ['slow_threshold_set', '20.0', '12.0'], ['slow_threshold_removed', null, '20.0']],
            array_map(static fn (array $row): array => [$row['decision'], $row['slow_threshold'], $row['previous']], $this->decisions()),
        );
    }

    public function testTheSameThresholdAgainChangesNothing(): void
    {
        $this->set(PlayerFixture::PLAYER_ADMIN, 4000, 12.0);
        $confirmedAt = $this->confirmation()?->confirmedAt;

        $this->set(PlayerFixture::PLAYER_REGULAR, 4000, 12.0);
        // Nothing to remove either
        $this->database->executeStatement('DELETE FROM suspicious_time_puzzle_confirmation');
        $this->set(PlayerFixture::PLAYER_REGULAR, 4000, null);

        self::assertNull($this->confirmation());
        self::assertCount(1, $this->decisions());
        self::assertNotNull($confirmedAt);
    }

    public function testAThresholdOfAnotherPiecesCountIsNoThresholdAnyMore(): void
    {
        $this->set(PlayerFixture::PLAYER_ADMIN, 4000, 12.0);
        $this->database->executeStatement('UPDATE puzzle SET pieces_count = 3000 WHERE id = :puzzleId', ['puzzleId' => SuspiciousTimesFixture::PUZZLE_HARBOUR]);
        $this->entityManager->clear();

        // The same number for the new count is a new threshold - the old one lapsed with its count
        $this->set(PlayerFixture::PLAYER_REGULAR, 3000, 12.0);

        $confirmation = $this->confirmation();
        self::assertSame([3000, 12.0], [$confirmation?->piecesCount, $confirmation?->slowThreshold]);
        self::assertSame([null, null], [$this->decisions()[1]['previous'], $this->decisions()[1]['time_id']]);
    }

    public function testAPuzzleACompetitionKeepsSecretIsRefused(): void
    {
        $this->database->executeStatement("UPDATE puzzle SET hide_until = NOW() + INTERVAL '1 day' WHERE id = :puzzleId", ['puzzleId' => SuspiciousTimesFixture::PUZZLE_HARBOUR]);

        try {
            $this->set(PlayerFixture::PLAYER_ADMIN, 4000, 12.0);
            self::fail('The threshold should have been refused.');
        } catch (PuzzleIsStillSecret) {
        }

        self::assertNull($this->confirmation());
        self::assertSame([], $this->decisions());
    }

    public function testAPiecesCountChangedMeanwhileIsRefused(): void
    {
        try {
            $this->set(PlayerFixture::PLAYER_ADMIN, 3000, 12.0);
            self::fail('The threshold should have been refused.');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(SuspiciousTimeCaseChanged::class, $exception->getPrevious());
        }

        self::assertNull($this->confirmation());
    }

    public function testAThresholdOutsideItsRangeIsRefused(): void
    {
        self::assertFalse(SetPuzzleSlowThreshold::isValid(2.9));
        self::assertTrue(SetPuzzleSlowThreshold::isValid(3.0));
        self::assertTrue(SetPuzzleSlowThreshold::isValid(100.0));
        self::assertFalse(SetPuzzleSlowThreshold::isValid(100.5));

        $this->expectException(HandlerFailedException::class);
        $this->set(PlayerFixture::PLAYER_ADMIN, 4000, 2.0);
    }

    private function set(string $moderatorId, int $seenPiecesCount, null|float $slowThreshold): void
    {
        $this->messageBus->dispatch(new SetPuzzleSlowThreshold(SuspiciousTimesFixture::PUZZLE_HARBOUR, $moderatorId, $seenPiecesCount, $slowThreshold));
        $this->entityManager->clear();
    }

    private function confirmation(): null|SuspiciousTimePuzzleConfirmation
    {
        $this->entityManager->clear();

        return self::getContainer()->get(SuspiciousTimePuzzleConfirmationRepository::class)->find(SuspiciousTimesFixture::PUZZLE_HARBOUR);
    }

    /**
     * @return list<array{decision: string, time_id: null|string, decided_by_id: null|string, slow_threshold: null|string, previous: null|string}>
     */
    private function decisions(): array
    {
        /** @var list<array{decision: string, time_id: null|string, decided_by_id: null|string, slow_threshold: null|string, previous: null|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            "SELECT decision, time_id, decided_by_id, snapshot->>'slow_threshold' AS slow_threshold, snapshot->>'previous_slow_threshold' AS previous
             FROM suspicious_time_decision WHERE puzzle_id = :puzzleId ORDER BY decided_at, id",
            ['puzzleId' => SuspiciousTimesFixture::PUZZLE_HARBOUR],
        );

        return $rows;
    }
}
