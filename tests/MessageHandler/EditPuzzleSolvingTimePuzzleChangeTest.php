<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Events\GroupSolvingTimeEdited;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\FirstTryAlreadyTaken;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\FirstTryScenario;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use SpeedPuzzling\Web\Value\SolvingTimePrediction;
use SpeedPuzzling\Web\Value\TimePredictionSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The edit form can move a result to another puzzle (docs/features/duplicate-results.md, Layer 4) - only whoever
 * tracked it, and everything that depends on the puzzle follows the new one.
 */
final class EditPuzzleSolvingTimePuzzleChangeTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTrackerMovesTheResultAndBothPuzzlesAreRecalculated(): void
    {
        // TIME_01: PLAYER_REGULAR, solo on PUZZLE_500_01, 30:00. Nobody solved PUZZLE_500_04 yet
        $puzzles = [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_04];
        $this->database->executeStatement('DELETE FROM puzzle_statistics WHERE puzzle_id IN (?, ?)', $puzzles);
        $this->database->executeStatement('DELETE FROM puzzle_difficulty WHERE puzzle_id IN (?, ?)', $puzzles);

        $this->edit(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleSolvingTimeFixture::TIME_01, puzzleId: PuzzleFixture::PUZZLE_500_04, comment: 'Wrong puzzle');

        /** @var array{puzzle_id: string, seconds_to_solve: int, comment: string} $row */
        $row = $this->database->fetchAssociative(
            'SELECT puzzle_id, seconds_to_solve, comment FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_01],
        );
        self::assertSame(PuzzleFixture::PUZZLE_500_04, $row['puzzle_id']);
        self::assertSame(1800, $row['seconds_to_solve']);
        self::assertSame('Wrong puzzle', $row['comment']);

        // The puzzle it left lost it, the new one has it - statistics and insights of both are fresh
        foreach ($puzzles as $puzzleId) {
            self::assertSame(
                $this->number('SELECT COUNT(*) FROM puzzle_solving_time WHERE puzzle_id = :id', $puzzleId),
                $this->number('SELECT solved_times_count FROM puzzle_statistics WHERE puzzle_id = :id', $puzzleId),
                $puzzleId,
            );
            self::assertSame(1, $this->number('SELECT COUNT(*) FROM puzzle_difficulty WHERE puzzle_id = :id', $puzzleId), $puzzleId);
        }

        self::assertSame(1, $this->number('SELECT solved_times_count FROM puzzle_statistics WHERE puzzle_id = :id', PuzzleFixture::PUZZLE_500_04));
    }

    public function testTheStoredPredictionIsForgottenAndEvaluatedAgain(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $time = $entityManager->find(PuzzleSolvingTime::class, PuzzleSolvingTimeFixture::TIME_01);
        self::assertNotNull($time);
        $time->recordPrediction(
            SolvingTimePrediction::predicted(new TimePredictionResult(1234, 1100, 1400, 1.0), TimePredictionSource::Live),
            new DateTimeImmutable('2026-09-01 10:00:00'),
        );
        $entityManager->flush();
        $entityManager->clear();
        $this->asyncTransport()->reset();

        $this->edit(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleSolvingTimeFixture::TIME_01, puzzleId: PuzzleFixture::PUZZLE_500_04);

        /** @var array{predictable: null|bool, predicted_seconds: null|int} $row */
        $row = $this->database->fetchAssociative(
            'SELECT predictable, predicted_seconds FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_01],
        );
        self::assertNull($row['predictable']);
        self::assertNull($row['predicted_seconds']);

        $backfills = array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $this->asyncTransport()->getSent()),
            static fn (object $message): bool => $message instanceof BackfillSolvingTimePredictions,
        ));
        self::assertCount(1, $backfills);
        self::assertSame([PuzzleSolvingTimeFixture::TIME_01], $backfills[0]->reevaluateTimeIds);
    }

    public function testTheRoundFollowsTheNewPuzzle(): void
    {
        // PUZZLE_500_03 is in no round of WJPC 2024, PUZZLE_500_01 is in its qualification
        $timeId = $this->add(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_500_03, competitionId: CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertNull($this->roundOf($timeId));

        $this->edit(PlayerFixture::PLAYER_REGULAR_USER_ID, $timeId, puzzleId: PuzzleFixture::PUZZLE_500_01, competitionId: CompetitionFixture::COMPETITION_WJPC_2024, time: '00:45:00');

        self::assertSame(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, $this->roundOf($timeId));
    }

    public function testTrackerMovesAPairResultAndTheOtherMemberIsTold(): void
    {
        // TIME_12: a pair of PLAYER_REGULAR (tracker) and PLAYER_PRIVATE on PUZZLE_1000_01
        $this->asyncTransport()->reset();

        $this->edit(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleSolvingTimeFixture::TIME_12, puzzleId: PuzzleFixture::PUZZLE_1000_04, time: '01:00:00', groupPlayers: ['#player2']);

        self::assertSame(PuzzleFixture::PUZZLE_1000_04, $this->puzzleOf(PuzzleSolvingTimeFixture::TIME_12));

        $groupEdits = array_filter(
            $this->asyncTransport()->getSent(),
            static fn ($envelope): bool => $envelope->getMessage() instanceof GroupSolvingTimeEdited,
        );
        self::assertCount(1, $groupEdits);
    }

    public function testOtherGroupMemberCanNotMoveTheResult(): void
    {
        try {
            $this->edit(PlayerFixture::PLAYER_PRIVATE_USER_ID, PuzzleSolvingTimeFixture::TIME_12, puzzleId: PuzzleFixture::PUZZLE_1000_04, time: '01:00:00', groupPlayers: ['#player2']);
            self::fail('A group member who did not track the result moved it');
        } catch (CanNotModifyOtherPlayersTime) {
            // HTTP exceptions leave the bus unwrapped (UnwrapHttpExceptionMiddleware)
        }

        self::assertSame(PuzzleFixture::PUZZLE_1000_01, $this->puzzleOf(PuzzleSolvingTimeFixture::TIME_12));
    }

    public function testOtherGroupMemberStillEditsTheRest(): void
    {
        // What the edit form sends for them: the result's own puzzle
        $this->edit(PlayerFixture::PLAYER_PRIVATE_USER_ID, PuzzleSolvingTimeFixture::TIME_12, puzzleId: PuzzleFixture::PUZZLE_1000_01, time: '01:05:00', groupPlayers: ['#player2']);

        self::assertSame(PuzzleFixture::PUZZLE_1000_01, $this->puzzleOf(PuzzleSolvingTimeFixture::TIME_12));
        self::assertSame(3900, $this->number('SELECT seconds_to_solve FROM puzzle_solving_time WHERE id = :id', PuzzleSolvingTimeFixture::TIME_12));
    }

    public function testFirstTryIsCheckedOnTheNewPuzzle(): void
    {
        $scenario = new FirstTryScenario(self::getContainer());
        $firstTry = $scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 3, firstTry: true);

        // A first try on PUZZLE_4000 - fine there, but PUZZLE_3000 already has the player's first try
        $moved = $this->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, PuzzleFixture::PUZZLE_4000, time: '05:00:00');
        $scenario->markFirstTry($moved);

        try {
            $this->edit(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, $moved, puzzleId: FirstTryScenario::PUZZLE, time: '05:00:00', firstAttempt: true);
            self::fail('A second first try reached the new puzzle');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(FirstTryAlreadyTaken::class, $exception->getPrevious());
        }

        self::assertSame(PuzzleFixture::PUZZLE_4000, $this->puzzleOf($moved));

        // "Make this result my first try" works across the move too
        $this->edit(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, $moved, puzzleId: FirstTryScenario::PUZZLE, time: '05:00:00', firstAttempt: true, firstTryResolution: FirstTryResolution::MoveHere);

        self::assertSame(FirstTryScenario::PUZZLE, $this->puzzleOf($moved));
        self::assertTrue($scenario->isFirstTry($moved));
        self::assertFalse($scenario->isFirstTry($firstTry));
    }

    /**
     * @param list<string> $groupPlayers
     */
    private function edit(
        string $userId,
        string $timeId,
        string $puzzleId,
        string $time = '00:30:00',
        null|string $comment = null,
        array $groupPlayers = [],
        null|string $competitionId = null,
        bool $firstAttempt = false,
        FirstTryResolution $firstTryResolution = FirstTryResolution::None,
    ): void {
        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: $userId,
            puzzleSolvingTimeId: $timeId,
            competitionId: $competitionId,
            time: $time,
            comment: $comment,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            finishedPuzzlesPhoto: null,
            firstAttempt: $firstAttempt,
            unboxed: false,
            firstTryResolution: $firstTryResolution,
            puzzleId: $puzzleId,
        ));
    }

    private function add(string $userId, string $puzzleId, string $time = '00:40:00', null|string $competitionId = null): string
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: $competitionId,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    private function puzzleOf(string $timeId): string
    {
        $puzzleId = $this->database->fetchOne('SELECT puzzle_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertIsString($puzzleId);

        return $puzzleId;
    }

    private function roundOf(string $timeId): null|string
    {
        $roundId = $this->database->fetchOne('SELECT competition_round_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);

        return is_string($roundId) ? $roundId : null;
    }

    private function number(string $query, string $id): int
    {
        $value = $this->database->fetchOne($query, ['id' => $id]);

        return is_numeric($value) ? (int) $value : -1;
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        assert($transport instanceof InMemoryTransport);

        return $transport;
    }
}
