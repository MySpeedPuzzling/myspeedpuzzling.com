<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DuplicatePuzzleSignalAlreadyResolved;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\DetectDuplicatePuzzleSignals;
use SpeedPuzzling\Web\Message\DismissDuplicatePuzzleSignal;
use SpeedPuzzling\Web\Message\ProposeDuplicatePuzzleMerge;
use SpeedPuzzling\Web\Results\DuplicatePuzzleSignalDetectionSummary;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The catalogue signal (docs/features/duplicate-results.md, Layer 4): the same person, the same time, the same day,
 * two puzzles with the same piece count - a merge hint for admins, never a duplicate result.
 */
final class DuplicatePuzzleSignalsTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testThePairOfPuzzlesIsStoredOnce(): void
    {
        // PUZZLE_1000_04 and PUZZLE_1000_05: 1000 pieces each; PUZZLE_500_04 has another piece count
        $this->add(PuzzleFixture::PUZZLE_1000_05, '01:11:11');
        $this->add(PuzzleFixture::PUZZLE_1000_04, '01:11:11');
        $this->add(PuzzleFixture::PUZZLE_500_04, '01:11:11');
        // Another day: no match
        $this->add(PuzzleFixture::PUZZLE_1000_04, '01:22:22', daysAgo: 4);
        $this->add(PuzzleFixture::PUZZLE_1000_05, '01:22:22', daysAgo: 5);

        $summary = $this->detect();

        self::assertSame(1, $summary->newSignals);
        self::assertSame(0, $summary->removedSignals);
        self::assertSame([
            [
                'puzzle_a_id' => PuzzleFixture::PUZZLE_1000_04,
                'puzzle_b_id' => PuzzleFixture::PUZZLE_1000_05,
                'status' => 'open',
                'matching_results' => 2,
                'matching_people' => 1,
                'example_player_id' => PlayerFixture::PLAYER_WITH_STRIPE,
                'example_seconds' => 4271,
                'example_day' => $this->day(3),
            ],
        ], $this->signals());

        // Nothing new the second time
        self::assertSame(0, $this->detect()->newSignals);
        self::assertCount(1, $this->signals());
    }

    public function testDecisionsStickAndOpenSignalsThatNoLongerMatchGo(): void
    {
        $this->add(PuzzleFixture::PUZZLE_1000_04, '01:11:11');
        $removed = $this->add(PuzzleFixture::PUZZLE_1000_05, '01:11:11');
        $this->add(PuzzleFixture::PUZZLE_1500_01, '02:22:22');
        $this->add(PuzzleFixture::PUZZLE_1500_02, '02:22:22');
        $this->detect();

        $this->messageBus->dispatch(new DismissDuplicatePuzzleSignal($this->signalIdOf(PuzzleFixture::PUZZLE_1500_01)));

        // The result on the wrong puzzle is gone (moved, deleted) - and so is the open signal; the dismissed one stays
        $this->database->executeStatement('DELETE FROM puzzle_solving_time WHERE id = :id', ['id' => $removed]);
        $this->database->executeStatement('DELETE FROM puzzle_solving_time WHERE puzzle_id = :id AND player_id = :playerId', [
            'id' => PuzzleFixture::PUZZLE_1500_02,
            'playerId' => PlayerFixture::PLAYER_WITH_STRIPE,
        ]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $summary = $this->detect();

        self::assertSame(1, $summary->removedSignals);
        self::assertSame(0, $summary->newSignals);
        self::assertSame([[PuzzleFixture::PUZZLE_1500_01, 'dismissed']], array_map(
            static fn (array $signal): array => [$signal['puzzle_a_id'], $signal['status']],
            $this->signals(),
        ));
    }

    public function testProposeMergeFilesAMergeRequestInTheReviewQueue(): void
    {
        $this->add(PuzzleFixture::PUZZLE_1000_04, '01:11:11');
        $this->add(PuzzleFixture::PUZZLE_1000_05, '01:11:11');
        $this->detect();
        $signalId = $this->signalIdOf(PuzzleFixture::PUZZLE_1000_04);
        $mergeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new ProposeDuplicatePuzzleMerge($signalId, PlayerFixture::PLAYER_ADMIN, $mergeRequestId));

        /** @var array{source_puzzle_id: string, reporter_id: string, status: string, reported_duplicate_puzzle_ids: string} $request */
        $request = $this->database->fetchAssociative(
            'SELECT source_puzzle_id, reporter_id, status, reported_duplicate_puzzle_ids FROM puzzle_merge_request WHERE id = :id',
            ['id' => $mergeRequestId],
        );
        self::assertSame(PuzzleFixture::PUZZLE_1000_04, $request['source_puzzle_id']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $request['reporter_id']);
        self::assertSame('pending', $request['status']);
        self::assertSame(
            [PuzzleFixture::PUZZLE_1000_04, PuzzleFixture::PUZZLE_1000_05],
            json_decode($request['reported_duplicate_puzzle_ids'], true, flags: JSON_THROW_ON_ERROR),
        );

        /** @var array{status: string, merge_request_id: string, resolved_at: null|string} $signal */
        $signal = $this->database->fetchAssociative(
            'SELECT status, merge_request_id, resolved_at FROM duplicate_puzzle_signal WHERE id = :id',
            ['id' => $signalId],
        );
        self::assertSame('merge_proposed', $signal['status']);
        self::assertSame($mergeRequestId, $signal['merge_request_id']);
        self::assertNotNull($signal['resolved_at']);

        // Already handled: neither a second request nor another decision
        try {
            $this->messageBus->dispatch(new ProposeDuplicatePuzzleMerge($signalId, PlayerFixture::PLAYER_ADMIN, Uuid::uuid7()->toString()));
            self::fail('A signal was proposed twice');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(DuplicatePuzzleSignalAlreadyResolved::class, $exception->getPrevious());
        }

        try {
            $this->messageBus->dispatch(new DismissDuplicatePuzzleSignal($signalId));
            self::fail('A proposed signal was dismissed');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(DuplicatePuzzleSignalAlreadyResolved::class, $exception->getPrevious());
        }

        $requests = $this->database->fetchOne(
            'SELECT COUNT(*) FROM puzzle_merge_request WHERE source_puzzle_id = :id',
            ['id' => PuzzleFixture::PUZZLE_1000_04],
        );
        self::assertSame(1, is_numeric($requests) ? (int) $requests : null);
    }

    public function testATeammateCopyOnAnotherPuzzleCountsForEveryMember(): void
    {
        // A pair result on one record, the partner's solo result with the same time on the other one
        $this->add(PuzzleFixture::PUZZLE_1000_04, '01:11:11', groupPlayers: ['#admin']);
        $this->add(PuzzleFixture::PUZZLE_1000_05, '01:11:11', userId: 'auth0|admin003');

        $this->detect();

        $signals = $this->signals();
        self::assertCount(1, $signals);
        self::assertSame(2, $signals[0]['matching_results']);
        self::assertSame(1, $signals[0]['matching_people']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $signals[0]['example_player_id']);
    }

    private function detect(): DuplicatePuzzleSignalDetectionSummary
    {
        $envelope = $this->messageBus->dispatch(new DetectDuplicatePuzzleSignals());
        $stamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($stamp);
        $summary = $stamp->getResult();
        self::assertInstanceOf(DuplicatePuzzleSignalDetectionSummary::class, $summary);

        return $summary;
    }

    /**
     * @param list<string> $groupPlayers
     */
    private function add(
        string $puzzleId,
        string $time,
        int $daysAgo = 3,
        array $groupPlayers = [],
        string $userId = PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
    ): string {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: null,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: self::getContainer()->get(ClockInterface::class)->now()->modify("-{$daysAgo} days"),
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    private function day(int $daysAgo): string
    {
        return self::getContainer()->get(ClockInterface::class)->now()->modify("-{$daysAgo} days")->format('Y-m-d');
    }

    /**
     * The signals about the puzzles this test logs on - fixtures may have their own.
     *
     * @return list<array<string, mixed>>
     */
    private function signals(): array
    {
        return $this->database->fetchAllAssociative(
            'SELECT puzzle_a_id, puzzle_b_id, status, matching_results, matching_people, example_player_id, example_seconds, example_day
            FROM duplicate_puzzle_signal
            WHERE puzzle_a_id IN (:ids) OR puzzle_b_id IN (:ids)
            ORDER BY puzzle_a_id',
            ['ids' => [PuzzleFixture::PUZZLE_1000_04, PuzzleFixture::PUZZLE_1000_05, PuzzleFixture::PUZZLE_500_04, PuzzleFixture::PUZZLE_1500_01, PuzzleFixture::PUZZLE_1500_02]],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    private function signalIdOf(string $puzzleAId): string
    {
        $id = $this->database->fetchOne('SELECT id FROM duplicate_puzzle_signal WHERE puzzle_a_id = :id', ['id' => $puzzleAId]);
        self::assertIsString($id);

        return $id;
    }
}
