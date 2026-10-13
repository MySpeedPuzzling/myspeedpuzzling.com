<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Message\RecordOnlineEntryEvents;
use SpeedPuzzling\Web\Results\RecordedOnlineEntryEvents;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineMissingPieceRule;
use SpeedPuzzling\Web\Value\OnlineUnfinishedMeasure;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * A batch of a phone's events (RecordOnlineEntryEventsHandler): the order things happened on the phone, rounds that
 * are not the current one any more, duplicates, and two requests storing the same event at once.
 */
final class RecordOnlineEntryEventsHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testAReadyRecordedEarlierButArrivingLaterDoesNotTakeTheEntryBack(): void
    {
        $now = self::nowMs();

        $takeover = $this->record(OnlineRoundsFixture::PARTICIPANT_CONNECTED, 'phone-b', [self::event('device.ready', $now)]);
        self::assertTrue($takeover->activeDeviceChanged);

        // The old phone's ready from a minute ago, sent only now (it was offline)
        $late = $this->record(OnlineRoundsFixture::PARTICIPANT_CONNECTED, OnlineRoundsFixture::DEVICE_CONNECTED, [self::event('device.ready', $now - 60_000)]);
        self::assertFalse($late->activeDeviceChanged);
        self::assertCount(1, $late->acks);

        self::assertSame('phone-b', $this->connection->fetchOne('SELECT active_device_id FROM online_entry WHERE id = :id', ['id' => OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED]));
    }

    public function testTheSameDeviceBeingReadyAgainIsNoTakeover(): void
    {
        $recorded = $this->record(OnlineRoundsFixture::PARTICIPANT_CONNECTED, OnlineRoundsFixture::DEVICE_CONNECTED, [self::event('device.ready', self::nowMs())]);

        self::assertFalse($recorded->activeDeviceChanged);
    }

    /**
     * Round 1 ended and round 2 is the current one: what the phone reports late about round 1 still lands on round 1
     */
    public function testLateReportsOfAFinishedRoundLandOnThatRound(): void
    {
        $roundOne = $this->entityManager->find(CompetitionRound::class, OnlineRoundsFixture::ROUND_ONLINE_SOLO);
        self::assertNotNull($roundOne);
        $roundOne->startStopwatch(new DateTimeImmutable('-2 hours'));
        $roundTwo = $this->onlineRound('Online Final');
        $participant = $this->entityManager->find(CompetitionParticipant::class, OnlineRoundsFixture::PARTICIPANT_ANONYMOUS);
        self::assertNotNull($participant);
        $entryTwo = new CompetitionParticipantRound(Uuid::uuid7(), $participant, $roundTwo);
        $this->entityManager->persist($entryTwo);
        $this->entityManager->flush();

        $now = self::nowMs();
        $timeUp = self::event('timeup.pieces', $now - 3_600_000, ['pieces' => 7], OnlineRoundsFixture::ROUND_ONLINE_SOLO);
        $hidden = self::event('page.hidden', $now, roundId: null);

        $recorded = $this->record(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS, 'phone-a', [$timeUp, $hidden], $roundTwo->id->toString());

        self::assertSame([], $recorded->refused);
        self::assertEqualsCanonicalizing([$timeUp['id'], $hidden['id']], $recorded->acks);
        self::assertSame(7, $this->connection->fetchOne('SELECT reported_pieces FROM online_entry WHERE entry_id = :entry', ['entry' => OnlineRoundsFixture::ENTRY_ANONYMOUS]));
        self::assertSame(
            $timeUp['id'],
            $this->connection->fetchOne('SELECT e.id FROM online_entry_event e JOIN online_entry o ON o.id = e.online_entry_id WHERE o.entry_id = :entry', ['entry' => OnlineRoundsFixture::ENTRY_ANONYMOUS]),
        );
        self::assertSame(
            $hidden['id'],
            $this->connection->fetchOne('SELECT e.id FROM online_entry_event e JOIN online_entry o ON o.id = e.online_entry_id WHERE o.entry_id = :entry', ['entry' => $entryTwo->id->toString()]),
        );
    }

    public function testTheRoundsMeasureIsTheReportsUnlessItNamesOne(): void
    {
        $onlineRound = $this->entityManager->find(OnlineRound::class, OnlineRoundsFixture::ONLINE_ROUND_SOLO);
        self::assertNotNull($onlineRound);
        $onlineRound->changeSettings(
            detectionMode: OnlineDetectionMode::Off,
            videoEnabled: false,
            participantsSeeEachOther: false,
            studioEnabled: false,
            recordingEnabled: false,
            unfinishedMeasure: OnlineUnfinishedMeasure::PiecesPlaced,
            missingPieceRule: OnlineMissingPieceRule::CompleteRequired,
        );
        $this->entityManager->flush();

        $this->record(OnlineRoundsFixture::PARTICIPANT_CONNECTED, OnlineRoundsFixture::DEVICE_CONNECTED, [self::event('timeup.pieces', self::nowMs(), ['pieces' => 480])]);

        self::assertSame(
            ['reported_pieces' => 480, 'reported_measure' => 'placed'],
            $this->connection->fetchAssociative('SELECT reported_pieces, reported_measure FROM online_entry WHERE id = :id', ['id' => OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED]),
        );
    }

    public function testTheSameEventTwiceInOneBatchIsStoredOnce(): void
    {
        $event = self::event('network.offline', self::nowMs());

        $recorded = $this->record(OnlineRoundsFixture::PARTICIPANT_CONNECTED, OnlineRoundsFixture::DEVICE_CONNECTED, [$event, $event]);

        self::assertSame([$event['id']], $recorded->acks);
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM online_entry_event WHERE id = :id', ['id' => $event['id']]));
    }

    /**
     * The phone sent again while its first request still ran - the later commit fails on the primary key and the
     * controller makes it a retryable error (ApiEventsControllerTest)
     */
    public function testAnotherRequestStoringTheSameEventMeanwhileFailsTheCommit(): void
    {
        $event = self::event('page.visible', self::nowMs());
        $eventId = $event['id'];
        self::assertIsString($eventId);

        $this->entityManager->getEventManager()->addEventListener(Events::preFlush, new class ($eventId) {
            private bool $done = false;

            public function __construct(
                private readonly string $eventId,
            ) {
            }

            public function preFlush(PreFlushEventArgs $args): void
            {
                if ($this->done) {
                    return;
                }

                $this->done = true;
                $args->getObjectManager()->getConnection()->insert('online_entry_event', [
                    'id' => $this->eventId,
                    'online_entry_id' => OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED,
                    'device_id' => OnlineRoundsFixture::DEVICE_CONNECTED,
                    'type' => 'page.visible',
                    'client_ms' => 1_792_000_000_000,
                    'received_at_ms' => 1_792_000_000_000,
                    'payload' => '{}',
                ]);
            }
        });

        $this->expectException(UniqueConstraintViolationException::class);

        $this->record(OnlineRoundsFixture::PARTICIPANT_CONNECTED, OnlineRoundsFixture::DEVICE_CONNECTED, [$event]);
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function record(string $participantId, string $deviceId, array $events, string $roundId = OnlineRoundsFixture::ROUND_ONLINE_SOLO): RecordedOnlineEntryEvents
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(new RecordOnlineEntryEvents(
            participantId: $participantId,
            roundId: $roundId,
            deviceId: $deviceId,
            events: $events,
        ));

        $recorded = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(RecordedOnlineEntryEvents::class, $recorded);

        return $recorded;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function event(string $type, int $clientMs, array $data = [], null|string $roundId = OnlineRoundsFixture::ROUND_ONLINE_SOLO): array
    {
        $event = ['id' => Uuid::uuid7()->toString(), 'type' => $type, 'clientMs' => $clientMs, 'offsetMs' => -3.25, 'rttMs' => 22, 'data' => $data];

        if ($roundId !== null) {
            $event['roundId'] = $roundId;
        }

        return $event;
    }

    private function onlineRound(string $name): CompetitionRound
    {
        $competition = $this->entityManager->find(Competition::class, OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP);
        self::assertNotNull($competition);

        $round = new CompetitionRound(
            id: Uuid::uuid7(),
            competition: $competition,
            name: $name,
            minutesLimit: 45,
            startsAt: new DateTimeImmutable('+30 days'),
            category: RoundCategory::Solo,
        );
        $this->entityManager->persist($round);
        $this->entityManager->persist(new OnlineRound(Uuid::uuid7(), $round, new DateTimeImmutable(), null));

        return $round;
    }

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
