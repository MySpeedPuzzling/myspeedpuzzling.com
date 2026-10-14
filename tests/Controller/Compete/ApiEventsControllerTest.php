<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Compete;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Message\VerifyOnlineMedia;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Value\OnlineEntryQuota;
use SpeedPuzzling\Web\Value\OnlineToken;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * `POST /api/compete/{token}/events` - a phone's outbox delivering its events (README §5.6, contract §6).
 */
final class ApiEventsControllerTest extends WebTestCase
{
    use CompeteApiRequests;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        self::forgetCachedAccess($this->browser);
    }

    public function testThePhonesFirstContactCreatesItsEntryAndStoresTheEvents(): void
    {
        $now = self::nowMs();
        $consent = self::event('consent.given', $now - 60_000);
        $ready = self::event('device.ready', $now - 1_500, ['browser' => 'Safari 18', 'camera' => ['w' => 1920, 'h' => 1080]]);

        $before = self::nowMs();
        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'events'), ['deviceId' => 'phone-a', 'events' => [$consent, $ready]]);

        self::assertResponseIsSuccessful();
        self::assertPrivateNoStore($this->browser);
        self::assertNoCookie($this->browser);

        $answer = self::json($this->browser);
        self::assertSame(['serverNowMs', 'acks', 'refused'], array_keys($answer));
        self::assertSame([$consent['id'], $ready['id']], $answer['acks']);
        self::assertSame([], $answer['refused']);

        $entry = $this->onlineEntry(OnlineRoundsFixture::ENTRY_ANONYMOUS);
        self::assertSame('phone-a', $entry['active_device_id']);
        self::assertSame((new DateTimeImmutable('@' . intdiv($now - 1_500, 1000)))->format('Y-m-d H:i:s'), $entry['ready_at']);
        self::assertSame(-25.5, $entry['clock_offset_ms']);
        self::assertSame(41.0, $entry['clock_rtt_ms']);

        $events = $this->connection()->fetchAllAssociative(
            'SELECT id, type, device_id, client_ms, received_at_ms, payload FROM online_entry_event WHERE online_entry_id = :id ORDER BY client_ms',
            ['id' => $entry['id']],
        );
        self::assertSame([$consent['id'], $ready['id']], array_column($events, 'id'));
        self::assertSame(['consent.given', 'device.ready'], array_column($events, 'type'));
        self::assertSame(['phone-a', 'phone-a'], array_column($events, 'device_id'));
        self::assertSame($now - 1_500, $events[1]['client_ms']);
        self::assertIsInt($events[1]['received_at_ms']);
        self::assertGreaterThanOrEqual($before, $events[1]['received_at_ms']);
        self::assertIsString($events[1]['payload']);
        // jsonb keeps the keys in its own order
        self::assertEquals(['browser' => 'Safari 18', 'camera' => ['w' => 1920, 'h' => 1080]], json_decode($events[1]['payload'], true));
    }

    public function testAnEventSentAgainIsAcknowledgedAndNeverStoredTwice(): void
    {
        $event = self::event('page.hidden', self::nowMs());
        $batch = ['deviceId' => 'phone-a', 'events' => [$event, $event]];

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'events'), $batch);
        self::assertSame([$event['id']], self::json($this->browser)['acks']);

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'events'), $batch);
        self::assertSame([$event['id']], self::json($this->browser)['acks']);

        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM online_entry_event WHERE id = :id', ['id' => $event['id']]));

        // The fixture's own event, sent again in upper case
        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'events'), ['deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED, 'events' => [
            self::event('consent.given', self::nowMs(), id: strtoupper(OnlineRoundsFixture::EVENT_CONSENT_CONNECTED)),
        ]]);
        self::assertSame([OnlineRoundsFixture::EVENT_CONSENT_CONNECTED], self::json($this->browser)['acks']);
    }

    public function testEveryRefusedEventSaysWhyAndTheOthersAreStored(): void
    {
        $now = self::nowMs();
        $fine = self::event('camera.lost', $now);
        $unknownType = self::event('finish.dance', $now);
        $noMoment = [...self::event('camera.back', $now), 'clientMs' => null];
        $unknownRound = self::event('camera.back', $now, roundId: Uuid::uuid7()->toString());
        $otherCompetitionsRound = self::event('camera.back', $now, roundId: OfficialResultsFixture::ROUND_GROUP_A);
        $notEntered = self::event('camera.back', $now, roundId: $this->secondOnlineRound());

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'events'), ['deviceId' => 'phone-a', 'events' => [
            $fine,
            $unknownType,
            $noMoment,
            $unknownRound,
            $otherCompetitionsRound,
            $notEntered,
            'not an event',
        ]]);

        self::assertResponseIsSuccessful();
        $answer = self::json($this->browser);
        self::assertSame([$fine['id']], $answer['acks']);
        self::assertEqualsCanonicalizing([
            ['id' => $unknownType['id'], 'reason' => 'unknown_type'],
            ['id' => $noMoment['id'], 'reason' => 'invalid_fields'],
            ['id' => $unknownRound['id'], 'reason' => 'wrong_round'],
            ['id' => $otherCompetitionsRound['id'], 'reason' => 'wrong_round'],
            ['id' => $notEntered['id'], 'reason' => 'not_entered'],
            ['id' => null, 'reason' => 'invalid_fields'],
        ], $answer['refused']);

        self::assertSame(1, $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM online_entry_event e JOIN online_entry o ON o.id = e.online_entry_id WHERE o.entry_id = :entry',
            ['entry' => OnlineRoundsFixture::ENTRY_ANONYMOUS],
        ));
    }

    public function testTheLatestTimeUpReportOnThePhonesClockWins(): void
    {
        // The round ran (a report is about the round's current run - from its start on)
        $entityManager = $this->browser->getContainer()->get(EntityManagerInterface::class);
        $round = $entityManager->find(CompetitionRound::class, OnlineRoundsFixture::ROUND_ONLINE_SOLO);
        self::assertNotNull($round);
        $round->startStopwatch(new DateTimeImmutable('-61 minutes'));
        $entityManager->flush();
        $now = self::nowMs();

        $this->sendConnected([self::event('timeup.pieces', $now - 10_000, ['pieces' => 12])]);
        $entry = $this->onlineEntry(OnlineRoundsFixture::ENTRY_CONNECTED);
        self::assertSame(12, $entry['reported_pieces']);
        // The round's measure when the report names none
        self::assertSame('left', $entry['reported_measure']);

        // Recorded earlier on the phone, arriving later: kept as an event, not as the report
        $this->sendConnected([self::event('timeup.pieces', $now - 20_000, ['pieces' => 30])]);
        self::assertSame(12, $this->onlineEntry(OnlineRoundsFixture::ENTRY_CONNECTED)['reported_pieces']);

        // A correction
        $this->sendConnected([self::event('timeup.pieces', $now, ['pieces' => 10, 'measure' => 'placed'])]);
        $entry = $this->onlineEntry(OnlineRoundsFixture::ENTRY_CONNECTED);
        self::assertSame(10, $entry['reported_pieces']);
        self::assertSame('placed', $entry['reported_measure']);

        self::assertSame(3, $this->connection()->fetchOne(
            "SELECT COUNT(*) FROM online_entry_event WHERE online_entry_id = :id AND type = 'timeup.pieces'",
            ['id' => OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED],
        ));
    }

    public function testAnUploadReportMarksTheMediaAndQueuesItsCheck(): void
    {
        $now = self::nowMs();
        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'media'), [
            'deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED,
            'kind' => 'burst',
            'takenMs' => $now - 5_000,
            'bytes' => 1_534_000,
            'contentType' => 'application/octet-stream',
        ]);
        self::assertResponseIsSuccessful();
        $mediaId = self::json($this->browser)['mediaId'];
        self::assertIsString($mediaId);

        $manifest = [
            ['offset' => 0, 'length' => 15_000, 'captureMs' => $now - 10_000],
            ['offset' => 15_000, 'length' => 14_500, 'captureMs' => $now - 9_900.5],
        ];
        $this->sendConnected([self::event('media.uploaded', $now, ['mediaId' => $mediaId, 'bytes' => 1_534_000, 'manifest' => $manifest])]);
        self::assertSame([], self::json($this->browser)['refused']);

        $media = $this->connection()->fetchAssociative('SELECT uploaded_at_ms, manifest, verified_bytes FROM online_entry_media WHERE id = :id', ['id' => $mediaId]);
        self::assertIsArray($media);
        self::assertSame($now, $media['uploaded_at_ms']);
        self::assertIsString($media['manifest']);
        self::assertEquals($manifest, json_decode($media['manifest'], true));
        // Checked later, by the async HEAD
        self::assertNull($media['verified_bytes']);

        $transport = $this->browser->getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $checks = array_values(array_filter(
            [...$transport->getSent()],
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof VerifyOnlineMedia,
        ));
        self::assertCount(1, $checks);
        $check = $checks[0]->getMessage();
        self::assertInstanceOf(VerifyOnlineMedia::class, $check);
        self::assertSame($mediaId, $check->mediaId);
    }

    public function testAnUploadReportOfAnotherParticipantsMediaIsRefused(): void
    {
        $event = self::event('media.uploaded', self::nowMs(), ['mediaId' => OnlineRoundsFixture::MEDIA_EMPTY_MAT_CONNECTED]);

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'events'), ['deviceId' => 'phone-a', 'events' => [$event]]);

        self::assertSame([['id' => $event['id'], 'reason' => 'invalid_fields']], self::json($this->browser)['refused']);
    }

    /**
     * README §5.4 "Several devices on one link": the latest device that is ready holds the entry; the other one hears
     * it on its next heartbeat
     */
    public function testATakeoverIsHeardOnTheOtherDevicesNextHeartbeat(): void
    {
        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'heartbeat'), ['deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED]);
        self::assertNull(self::json($this->browser)['takenOverBy']);

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'events'), ['deviceId' => 'phone-b', 'events' => [
            self::event('device.ready', self::nowMs()),
        ]]);
        self::assertSame([], self::json($this->browser)['refused']);
        self::assertSame('phone-b', $this->onlineEntry(OnlineRoundsFixture::ENTRY_CONNECTED)['active_device_id']);

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'heartbeat'), ['deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED]);
        self::assertSame('phone-b', self::json($this->browser)['takenOverBy']);

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'heartbeat'), ['deviceId' => 'phone-b']);
        self::assertNull(self::json($this->browser)['takenOverBy']);
    }

    /**
     * The phone sent again while its first request still ran: the later commit fails on the primary key - a retryable
     * error, and the next send is acknowledged
     */
    public function testTheSameEventStoredByAnotherRequestMeanwhileIsAskedAgain(): void
    {
        $eventId = Uuid::uuid7()->toString();
        $event = self::event('page.visible', self::nowMs(), id: $eventId);
        $entityManager = $this->browser->getContainer()->get(EntityManagerInterface::class);
        // The other request's row lands between this one's check and its commit
        $entityManager->getEventManager()->addEventListener(Events::preFlush, new class ($eventId) {
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

        $this->sendConnected([$event]);

        self::assertRefused($this->browser, 503, 'retry');
        self::assertSame('1', $this->browser->getResponse()->headers->get('Retry-After'));

        $this->sendConnected([$event]);

        self::assertResponseIsSuccessful();
        self::assertSame([$eventId], self::json($this->browser)['acks']);
    }

    public function testRequestsThatCanNotBeRead(): void
    {
        $url = self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'events');

        self::postJson($this->browser, self::api('NoSuchLinkAnywhere2222', 'events'), ['deviceId' => 'phone-a', 'events' => []]);
        self::assertRefused($this->browser, 404, 'unknown_link');

        $this->browser->request('POST', $url, content: 'events');
        self::assertRefused($this->browser, 400, 'invalid_json');

        self::postJson($this->browser, $url, ['events' => []]);
        self::assertRefused($this->browser, 422, 'invalid_fields');

        self::postJson($this->browser, $url, ['deviceId' => 'phone-a', 'events' => ['a' => self::event('page.hidden', self::nowMs())]]);
        self::assertRefused($this->browser, 422, 'invalid_fields');

        $tooMany = array_map(static fn (int $i): array => self::event('page.hidden', self::nowMs() + $i), range(1, 51));
        self::postJson($this->browser, $url, ['deviceId' => 'phone-a', 'events' => $tooMany]);
        self::assertRefused($this->browser, 422, 'too_many_events');

        // Fifty is a batch
        self::postJson($this->browser, $url, ['deviceId' => 'phone-a', 'events' => array_slice($tooMany, 0, 50)]);
        self::assertResponseIsSuccessful();
        self::assertCount(50, (array) self::json($this->browser)['acks']);

        // Nothing to send is nothing to do
        self::postJson($this->browser, $url, ['deviceId' => 'phone-a', 'events' => []]);
        self::assertResponseIsSuccessful();
        self::assertSame([], self::json($this->browser)['acks']);
    }

    public function testAnEntryHoldingAllTheEventsItMayTakesNoMore(): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO online_entry_event (id, online_entry_id, device_id, type, client_ms, received_at_ms, payload)
             SELECT gen_random_uuid(), :onlineEntry, 'phone', 'page.hidden', 1792000000000 + n, 1792000000000 + n, '{}'
             FROM generate_series(1, :count) AS n",
            // The fixture's two events are counted too
            ['onlineEntry' => OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED, 'count' => OnlineEntryQuota::MAX_EVENTS_PER_ENTRY - 2],
        );
        $event = self::event('page.visible', self::nowMs());

        $this->sendConnected([$event]);

        self::assertResponseIsSuccessful();
        self::assertSame([['id' => $event['id'], 'reason' => 'too_many_events']], self::json($this->browser)['refused']);
    }

    public function testALinkSendingTooManyBatchesIsTold(): void
    {
        $token = OnlineToken::generate();
        $entityManager = $this->browser->getContainer()->get(EntityManagerInterface::class);
        $participant = $entityManager->find(CompetitionParticipant::class, OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN);
        self::assertNotNull($participant);
        $participant->assignOnlineToken($token, new DateTimeImmutable());
        $entityManager->flush();

        $limiter = $this->browser->getContainer()->get('online_rate_limiter.online_participant_events');
        $limiter->create($token)->consume($limiter->create($token)->consume(0)->getLimit());

        self::postJson($this->browser, self::api($token, 'events'), ['deviceId' => 'phone-a', 'events' => []]);

        self::assertRefused($this->browser, 429, 'rate_limited');
    }

    /**
     * Postgres refuses a NUL in text and jsonb - it is refused before (a 500 otherwise), like any id that is not a
     * canonical UUID
     */
    public function testHostileInputNeverReachesPostgres(): void
    {
        $now = self::nowMs();
        $nul = self::event('device.ready', $now, ['browser' => "Safari\0"]);
        $braces = [...self::event('page.hidden', $now), 'id' => '{' . Uuid::uuid7()->toString() . '}'];
        $urnRound = [...self::event('page.hidden', $now), 'roundId' => 'urn:uuid:' . OnlineRoundsFixture::ROUND_ONLINE_SOLO];

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'events'), ['deviceId' => 'phone-a', 'events' => [$nul, $braces, $urnRound]]);

        self::assertResponseIsSuccessful();
        $answer = self::json($this->browser);
        self::assertSame([], $answer['acks']);
        self::assertSame(['invalid_fields', 'invalid_fields', 'invalid_fields'], array_column((array) $answer['refused'], 'reason'));

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'events'), ['deviceId' => "phone\0", 'events' => [self::event('page.hidden', $now)]]);
        self::assertRefused($this->browser, 422, 'invalid_fields');
    }

    public function testACompetitionWithoutOnlineTimingTakesNoEvents(): void
    {
        $entityManager = $this->browser->getContainer()->get(EntityManagerInterface::class);
        $participant = $entityManager->find(CompetitionParticipant::class, OfficialResultsFixture::PARTICIPANT_BEN);
        self::assertNotNull($participant);
        $participant->assignOnlineToken('BenWithoutAnyRound2222', new DateTimeImmutable());
        $entityManager->flush();

        self::postJson($this->browser, self::api('BenWithoutAnyRound2222', 'events'), ['deviceId' => 'phone-a', 'events' => [self::event('page.hidden', self::nowMs(), roundId: null)]]);

        self::assertRefused($this->browser, 409, 'round_not_online');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function event(
        string $type,
        int $clientMs,
        array $data = [],
        null|string $roundId = OnlineRoundsFixture::ROUND_ONLINE_SOLO,
        null|string $id = null,
    ): array {
        $event = [
            'id' => $id ?? Uuid::uuid7()->toString(),
            'type' => $type,
            'clientMs' => $clientMs,
            'perfMs' => 2_832_345.5,
            'offsetMs' => -25.5,
            'rttMs' => 41,
            'data' => $data,
        ];

        if ($roundId !== null) {
            $event['roundId'] = $roundId;
        }

        return $event;
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function sendConnected(array $events): void
    {
        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'events'), [
            'deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED,
            'events' => $events,
        ]);
    }

    /**
     * Another round of the Online Test Cup with online timing - nobody is entered in it
     */
    private function secondOnlineRound(): string
    {
        $entityManager = $this->browser->getContainer()->get(EntityManagerInterface::class);
        $competition = $entityManager->find(Competition::class, OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP);
        self::assertNotNull($competition);

        $round = new CompetitionRound(
            id: Uuid::uuid7(),
            competition: $competition,
            name: 'Online Final',
            minutesLimit: 45,
            startsAt: new DateTimeImmutable('+30 days'),
            category: RoundCategory::Solo,
        );
        $entityManager->persist($round);
        $entityManager->persist(new OnlineRound(Uuid::uuid7(), $round, new DateTimeImmutable(), null));
        $entityManager->flush();

        return $round->id->toString();
    }

    /**
     * @return array<string, mixed>
     */
    private function onlineEntry(string $entryId): array
    {
        $row = $this->connection()->fetchAssociative('SELECT * FROM online_entry WHERE entry_id = :entry', ['entry' => $entryId]);
        self::assertIsArray($row, 'The online entry exists');

        return $row;
    }

    private function connection(): Connection
    {
        return $this->browser->getContainer()->get(Connection::class);
    }
}
