<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\OnlineEntry;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Message\ResetRoundStopwatch;
use SpeedPuzzling\Web\Message\StartRoundStopwatch;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\OnlineConfirmedSource;
use SpeedPuzzling\Web\Value\OnlineReviewStatus;
use SpeedPuzzling\Web\Value\OnlineUnfinishedMeasure;
use SpeedPuzzling\Web\Value\RoundStopwatchRefusal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ResetRoundStopwatchHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionRoundRepository $competitionRoundRepository;
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->competitionRoundRepository = self::getContainer()->get(CompetitionRoundRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testResetClearsEverything(): void
    {
        $this->messageBus->dispatch(new StartRoundStopwatch(
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
        ));

        $this->messageBus->dispatch(new ResetRoundStopwatch(
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            competitionId: $this->competitionRoundRepository->get(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION)->competition->id->toString(),
        ));

        $round = $this->competitionRoundRepository->get(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        self::assertNull($round->stopwatchStartedAt);
        self::assertNull($round->stopwatchStatus);
    }

    /**
     * A Reset of an online round starts a fresh run: what the phones reported and the review decided belongs to the run
     * that was - cleared; the events stay stored, the device and its readiness stay
     */
    public function testAnOnlineRoundStartsAFreshRun(): void
    {
        $this->startOnlineRound();
        $onlineEntry = $this->onlineEntry();
        $onlineEntry->reportedMeasure = OnlineUnfinishedMeasure::PiecesLeft;
        $onlineEntry->reportedPieces = 12;
        // Confirmed, and its official result cleared at the results desk afterwards
        $onlineEntry->confirm(1_792_002_832_345, OnlineConfirmedSource::Button, $this->reviewer(), TestClock::now());
        $this->entityManager->flush();
        $events = $this->eventCount();

        $this->reset();

        $round = $this->competitionRoundRepository->get(OnlineRoundsFixture::ROUND_ONLINE_SOLO);
        self::assertNull($round->stopwatchStatus);
        self::assertNull($round->stopwatchStartedAt);

        self::assertSame([
            'reported_measure' => null,
            'reported_pieces' => null,
            'review_status' => OnlineReviewStatus::Pending->value,
            'confirmed_moment_ms' => null,
            'confirmed_source' => null,
            'reviewed_by_id' => null,
            'reviewed_at' => null,
            'active_device_id' => OnlineRoundsFixture::DEVICE_CONNECTED,
            'ready' => true,
        ], $this->connection->fetchAssociative(
            'SELECT reported_measure, reported_pieces, review_status, confirmed_moment_ms, confirmed_source, reviewed_by_id, reviewed_at, active_device_id, ready_at IS NOT NULL AS ready
             FROM online_entry WHERE id = :id',
            ['id' => OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED],
        ));
        self::assertSame($events, $this->eventCount(), 'an earlier run\'s events are never deleted');
    }

    /**
     * Once a result of the round is recorded the Reset is refused - the results desk clears the results first
     */
    public function testAnOnlineRoundWithOfficialResultsIsNotReset(): void
    {
        $this->startOnlineRound();
        $onlineEntry = $this->onlineEntry();
        $onlineEntry->reportedPieces = 12;
        $this->entityManager->flush();
        $this->connection->executeStatement(
            'UPDATE competition_participant_round SET result_seconds = 1234 WHERE id = :id',
            ['id' => OnlineRoundsFixture::ENTRY_CONNECTED],
        );

        try {
            $this->reset();
            self::fail('A Reset over official results must be refused');
        } catch (RoundStopwatchRefused $refused) {
            self::assertSame(RoundStopwatchRefusal::ResultsConfirmed, $refused->reason);
        }

        self::assertSame(
            ['stopwatch_status' => 'running', 'reported_pieces' => 12],
            $this->connection->fetchAssociative(
                'SELECT cr.stopwatch_status, oe.reported_pieces FROM competition_round cr, online_entry oe WHERE cr.id = :round AND oe.id = :entry',
                ['round' => OnlineRoundsFixture::ROUND_ONLINE_SOLO, 'entry' => OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED],
            ),
        );

        // "Did not start" is an official result too
        $this->connection->executeStatement(
            'UPDATE competition_participant_round SET result_seconds = NULL, result_did_not_start = TRUE WHERE id = :id',
            ['id' => OnlineRoundsFixture::ENTRY_CONNECTED],
        );
        $this->expectException(RoundStopwatchRefused::class);
        $this->reset();
    }

    /**
     * In person nothing is checked - a round with results resets as before
     */
    public function testAnInPersonRoundWithResultsResetsAsBefore(): void
    {
        $this->messageBus->dispatch(new StartRoundStopwatch(roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION));
        self::assertGreaterThan(0, $this->connection->executeStatement(
            'UPDATE competition_participant_round SET result_seconds = 1234 WHERE round_id = :round',
            ['round' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION],
        ));

        $this->messageBus->dispatch(new ResetRoundStopwatch(
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            competitionId: $this->competitionRoundRepository->get(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION)->competition->id->toString(),
        ));

        self::assertNull($this->competitionRoundRepository->get(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION)->stopwatchStatus);
    }

    public function testARoundOfAnotherEventIsNotFound(): void
    {
        $this->expectException(CompetitionRoundNotFound::class);

        $this->messageBus->dispatch(new ResetRoundStopwatch(
            roundId: OnlineRoundsFixture::ROUND_ONLINE_SOLO,
            competitionId: $this->competitionRoundRepository->get(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION)->competition->id->toString(),
        ));
    }

    private function reset(): void
    {
        $this->messageBus->dispatch(new ResetRoundStopwatch(
            roundId: OnlineRoundsFixture::ROUND_ONLINE_SOLO,
            competitionId: OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP,
        ));
    }

    private function startOnlineRound(): void
    {
        $round = $this->competitionRoundRepository->get(OnlineRoundsFixture::ROUND_ONLINE_SOLO);
        $round->startStopwatch(TestClock::now()->modify('-30 minutes'));
        $this->entityManager->flush();
    }

    private function onlineEntry(): OnlineEntry
    {
        $onlineEntry = $this->entityManager->find(OnlineEntry::class, OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED);
        self::assertNotNull($onlineEntry);

        return $onlineEntry;
    }

    private function reviewer(): Player
    {
        $reviewer = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_ADMIN);
        self::assertNotNull($reviewer);

        return $reviewer;
    }

    private function eventCount(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM online_entry_event WHERE online_entry_id = :id', ['id' => OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED]);
        self::assertIsInt($count);

        return $count;
    }
}
