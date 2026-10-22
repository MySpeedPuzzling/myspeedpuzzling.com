<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Compete;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Ramsey\Uuid\Rfc4122\FieldsInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\OnlineEntry;
use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Services\OnlineRounds\FakeOnlineMediaStorage;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\OnlineConfirmedSource;
use SpeedPuzzling\Web\Value\OnlineEntryQuota;
use SpeedPuzzling\Web\Value\OnlineToken;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `POST /api/compete/{token}/media` - a presigned upload straight to object storage (README §5.11): the row first,
 * then the URL signed for the declared type and length.
 */
final class ApiMediaControllerTest extends WebTestCase
{
    use CompeteApiRequests;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        self::forgetCachedAccess($this->browser);
    }

    public function testAPhotosUploadOnThePhonesFirstContact(): void
    {
        $takenMs = self::nowMs() - 2_000;

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'media'), [
            'deviceId' => 'phone-a',
            'roundId' => OnlineRoundsFixture::ROUND_ONLINE_SOLO,
            'kind' => 'empty_mat',
            'takenMs' => $takenMs,
            'bytes' => 182_000,
            'contentType' => 'image/jpeg',
        ]);

        self::assertResponseIsSuccessful();
        self::assertPrivateNoStore($this->browser);
        self::assertNoCookie($this->browser);

        $answer = self::json($this->browser);
        self::assertSame(['serverNowMs', 'mediaId', 'store', 'method', 'url', 'headers', 'expiresAtMs'], array_keys($answer));
        self::assertIsString($answer['mediaId']);
        self::assertSame(7, self::uuidVersion(Uuid::fromString($answer['mediaId'])));
        self::assertSame('primary', $answer['store']);
        self::assertSame('PUT', $answer['method']);
        self::assertSame(['Content-Type' => 'image/jpeg', 'Content-Length' => '182000'], $answer['headers']);
        self::assertIsInt($answer['serverNowMs']);
        self::assertIsInt($answer['expiresAtMs']);
        // Presigned a moment before the answer's clock sample was taken
        $presignedAtMs = $answer['expiresAtMs'] - FakeOnlineMediaStorage::PUT_LIFETIME_SECONDS * 1000;
        self::assertLessThanOrEqual($answer['serverNowMs'], $presignedAtMs);
        self::assertGreaterThan($answer['serverNowMs'] - 1_000, $presignedAtMs);

        $key = sprintf('online/%s/%s/%s/%s.jpg', OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP, OnlineRoundsFixture::ROUND_ONLINE_SOLO, OnlineRoundsFixture::PARTICIPANT_ANONYMOUS, $answer['mediaId']);
        self::assertSame(FakeOnlineMediaStorage::BASE_URL . '/primary/' . $key . '?X-Fake-Signature=put', $answer['url']);

        $media = $this->connection()->fetchAssociative(
            'SELECT m.*, o.entry_id FROM online_entry_media m JOIN online_entry o ON o.id = m.online_entry_id WHERE m.id = :id',
            ['id' => $answer['mediaId']],
        );
        self::assertIsArray($media);
        self::assertSame(OnlineRoundsFixture::ENTRY_ANONYMOUS, $media['entry_id']);
        self::assertSame('empty_mat', $media['kind']);
        self::assertSame($takenMs, $media['taken_ms']);
        self::assertSame('primary', $media['store']);
        self::assertSame($key, $media['object_key']);
        self::assertSame('image/jpeg', $media['content_type']);
        self::assertSame(182_000, $media['declared_bytes']);
        self::assertNull($media['uploaded_at_ms']);
        self::assertNull($media['related_event_id']);
    }

    public function testABurstOfATapIsOneBinaryObject(): void
    {
        $eventId = Uuid::uuid7()->toString();

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'media'), [
            'deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED,
            'kind' => 'burst',
            'takenMs' => self::nowMs(),
            'bytes' => 1_534_000,
            'contentType' => 'application/octet-stream',
            'eventId' => $eventId,
            // The phone's previous upload of it failed at the store - the breaker counts it, nothing else changes
            'previousFailure' => ['status' => 503, 'store' => 'primary', 'mediaId' => Uuid::uuid7()->toString()],
        ]);

        self::assertResponseIsSuccessful();
        $answer = self::json($this->browser);
        self::assertIsString($answer['url']);
        self::assertIsString($answer['mediaId']);
        self::assertStringContainsString('/' . OnlineRoundsFixture::PARTICIPANT_CONNECTED . '/' . $answer['mediaId'] . '.bin?', $answer['url']);
        self::assertSame(['Content-Type' => 'application/octet-stream', 'Content-Length' => '1534000'], $answer['headers']);

        $media = $this->connection()->fetchAssociative('SELECT kind, content_type, related_event_id, online_entry_id FROM online_entry_media WHERE id = :id', ['id' => $answer['mediaId']]);
        self::assertSame([
            'kind' => 'burst',
            'content_type' => 'application/octet-stream',
            'related_event_id' => $eventId,
            'online_entry_id' => OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED,
        ], $media);
    }

    public function testWhatTheStoreWouldNotTake(): void
    {
        $url = self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'media');
        $photo = [
            'deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED,
            'kind' => 'periodic',
            'takenMs' => self::nowMs(),
            'bytes' => 35_000,
            'contentType' => 'image/jpeg',
        ];

        self::postJson($this->browser, $url, [...$photo, 'kind' => 'selfie']);
        self::assertRefused($this->browser, 422, 'media_kind_unknown');

        self::postJson($this->browser, $url, [...$photo, 'bytes' => OnlineEntryQuota::MAX_MEDIA_BYTES + 1]);
        self::assertRefused($this->browser, 422, 'media_too_large');

        $invalid = [
            'no device' => [...$photo, 'deviceId' => ''],
            'no bytes' => [...$photo, 'bytes' => 0],
            'bytes as text' => [...$photo, 'bytes' => '35000'],
            'a photo as binary' => [...$photo, 'contentType' => 'application/octet-stream'],
            'another image type' => [...$photo, 'contentType' => 'image/png'],
            'a burst as a photo' => [...$photo, 'kind' => 'burst'],
            'seconds instead of milliseconds' => [...$photo, 'takenMs' => intdiv(self::nowMs(), 1000)],
            'a round that is no id' => [...$photo, 'roundId' => 'round-1'],
            'an event that is no id' => [...$photo, 'eventId' => 'tap-1'],
            'a round in braces' => [...$photo, 'roundId' => '{' . OnlineRoundsFixture::ROUND_ONLINE_SOLO . '}'],
            'an event as a URN' => [...$photo, 'eventId' => 'urn:uuid:' . Uuid::uuid7()->toString()],
            'a device with a NUL' => [...$photo, 'deviceId' => "phone\0"],
        ];

        foreach ($invalid as $case => $body) {
            self::postJson($this->browser, $url, $body);
            self::assertResponseStatusCodeSame(422, $case);
            self::assertSame('invalid_fields', self::json($this->browser)['error'], $case);
        }

        self::assertSame(0, $this->connection()->fetchOne("SELECT COUNT(*) FROM online_entry_media WHERE kind = 'periodic'"));
    }

    public function testTheRoundMustBeAnOnlineRoundTheParticipantIsEnteredIn(): void
    {
        $url = self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'media');
        $photo = [
            'deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED,
            'kind' => 'start',
            'takenMs' => self::nowMs(),
            'bytes' => 250_000,
            'contentType' => 'image/jpeg',
        ];

        self::postJson($this->browser, $url, [...$photo, 'roundId' => OfficialResultsFixture::ROUND_GROUP_A]);
        self::assertRefused($this->browser, 409, 'round_not_online');

        self::postJson($this->browser, $url, [...$photo, 'roundId' => Uuid::uuid7()->toString()]);
        self::assertRefused($this->browser, 409, 'round_not_online');

        self::postJson($this->browser, $url, [...$photo, 'roundId' => $this->secondOnlineRound()]);
        self::assertRefused($this->browser, 409, 'not_entered');

        self::postJson($this->browser, self::api('NoSuchLinkAnywhere2222', 'media'), $photo);
        self::assertRefused($this->browser, 404, 'unknown_link');
    }

    /**
     * The review confirmed the result - the entry's evidence is closed
     */
    public function testNoUploadAfterTheReviewConfirmedTheResult(): void
    {
        $entityManager = $this->browser->getContainer()->get(EntityManagerInterface::class);
        $onlineEntry = $entityManager->find(OnlineEntry::class, OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED);
        $reviewer = $entityManager->find(Player::class, PlayerFixture::PLAYER_ADMIN);
        self::assertNotNull($onlineEntry);
        self::assertNotNull($reviewer);
        $onlineEntry->confirm(1_792_002_832_345, OnlineConfirmedSource::Button, $reviewer, TestClock::now());
        $entityManager->flush();

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'media'), self::photo());

        self::assertRefused($this->browser, 409, 'entry_confirmed');
    }

    public function testAnEntryAtItsCapOfBytesTakesNoMore(): void
    {
        $this->insertMedia(1, OnlineEntryQuota::MAX_MEDIA_BYTES_PER_ENTRY - 1_000, verified: true);

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'media'), self::photo());

        self::assertRefused($this->browser, 422, 'media_quota_exceeded');
    }

    public function testAnEntryAtItsCapOfObjectsTakesNoMore(): void
    {
        // The fixture's own photo is one of them
        $this->insertMedia(OnlineEntryQuota::MAX_MEDIA_PER_ENTRY - 1, 35_000, verified: true);

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'media'), self::photo());

        self::assertRefused($this->browser, 422, 'media_quota_exceeded');
    }

    /**
     * Uploads asked for lately that never arrived (a store that is down, or a client that never uploads) - come back
     * later
     */
    public function testTooManyUploadsPendingWaitAWhile(): void
    {
        $this->insertMedia(OnlineEntryQuota::MAX_PENDING_MEDIA_PER_ENTRY, 35_000, verified: false);

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'media'), self::photo());

        self::assertRefused($this->browser, 429, 'too_many_pending_media');
        self::assertSame((string) OnlineEntryQuota::PENDING_MEDIA_RETRY_AFTER_SECONDS, $this->browser->getResponse()->headers->get('Retry-After'));
    }

    /**
     * Uploads the phone reported done count no more, verified or not - an async check that lags behind during an event
     * never holds a phone up
     */
    public function testUploadsWaitingForTheirCheckNeverHoldThePhoneUp(): void
    {
        $this->insertMedia(OnlineEntryQuota::MAX_PENDING_MEDIA_PER_ENTRY + 20, 35_000, verified: false, uploaded: true);

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'media'), self::photo());

        self::assertResponseIsSuccessful();
        self::assertIsString(self::json($this->browser)['url'] ?? null);
    }

    /**
     * A presign after a failed upload names the one the phone gave up (`previousFailure.mediaId`): it never arrives -
     * marked abandoned, the review says "never arrived" instead of "pending"; whatever else is named is left alone
     */
    public function testAPresignAfterAFailedUploadMarksTheOneGivenUpAbandoned(): void
    {
        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'media'), self::photo());
        $givenUp = self::json($this->browser)['mediaId'] ?? null;
        self::assertIsString($givenUp);

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'media'), [
            ...self::photo(),
            'previousFailure' => ['status' => 0, 'store' => 'primary', 'mediaId' => $givenUp],
        ]);

        self::assertResponseIsSuccessful();
        self::assertNotSame($givenUp, self::json($this->browser)['mediaId'] ?? null);
        self::assertNotNull($this->abandonedAt($givenUp));

        // A refused URL (403) names it as well; another participant's media, an uploaded one, an id that is no media:
        // nothing changes
        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'media'), [...self::photo(), 'deviceId' => 'phone-a']);
        $someoneElses = self::json($this->browser)['mediaId'] ?? null;
        self::assertIsString($someoneElses);

        foreach ([$someoneElses, OnlineRoundsFixture::MEDIA_EMPTY_MAT_CONNECTED, Uuid::uuid7()->toString(), 'not-an-id'] as $named) {
            self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'media'), [
                ...self::photo(),
                'previousFailure' => ['status' => 403, 'store' => 'primary', 'mediaId' => $named],
            ]);

            self::assertResponseIsSuccessful();
        }

        self::assertNull($this->abandonedAt($someoneElses));
        self::assertNull($this->abandonedAt(OnlineRoundsFixture::MEDIA_EMPTY_MAT_CONNECTED));
    }

    /**
     * During a store outage every retry is a new row: the ones the phone gave up are pending no more - the one this
     * presign replaces included, so a phone at the cap can retry its item
     */
    public function testUploadsGivenUpNeverHoldThePhoneUp(): void
    {
        $this->insertMedia(OnlineEntryQuota::MAX_PENDING_MEDIA_PER_ENTRY + 20, 35_000, verified: false, abandoned: true);
        $this->insertMedia(OnlineEntryQuota::MAX_PENDING_MEDIA_PER_ENTRY - 1, 35_000, verified: false);

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'media'), self::photo());
        self::assertResponseIsSuccessful();
        $failed = self::json($this->browser)['mediaId'] ?? null;
        self::assertIsString($failed);

        // At the cap now - only the retry of the item that failed goes through
        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'media'), self::photo());
        self::assertRefused($this->browser, 429, 'too_many_pending_media');

        self::postJson($this->browser, self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'media'), [
            ...self::photo(),
            'previousFailure' => ['status' => 503, 'store' => 'primary', 'mediaId' => $failed],
        ]);
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->abandonedAt($failed));
    }

    public function testALinkAskingForTooManyUploadsIsTold(): void
    {
        $token = OnlineToken::generate();
        $entityManager = $this->browser->getContainer()->get(EntityManagerInterface::class);
        $participant = $entityManager->find(CompetitionParticipant::class, OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN);
        self::assertNotNull($participant);
        $participant->assignOnlineToken($token, TestClock::now());
        $entityManager->flush();

        $limiter = $this->browser->getContainer()->get('online_rate_limiter.online_participant_media');
        $limiter->create($token)->consume($limiter->create($token)->consume(0)->getLimit());

        self::postJson($this->browser, self::api($token, 'media'), self::photo());

        self::assertRefused($this->browser, 429, 'rate_limited');
        self::assertNotNull($this->browser->getResponse()->headers->get('Retry-After'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function photo(): array
    {
        return [
            'deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED,
            'kind' => 'periodic',
            'takenMs' => self::nowMs(),
            'bytes' => 35_000,
            'contentType' => 'image/jpeg',
        ];
    }

    /**
     * @param bool $uploaded the phone reported it done (`media.uploaded`) - verified ones are uploaded too
     * @param bool $abandoned never arrived (OnlineEntryMedia::$abandonedAt)
     */
    private function insertMedia(int $count, int $bytes, bool $verified, bool $uploaded = false, bool $abandoned = false): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO online_entry_media (id, online_entry_id, kind, taken_ms, store, object_key, content_type, declared_bytes, uploaded_at_ms, verified_bytes, abandoned_at, created_at)
             SELECT gen_random_uuid(), :onlineEntry, 'periodic', 1792000000000, 'primary', 'online/quota/' || n || '.jpg', 'image/jpeg', :bytes, :uploadedAt, :verified, :abandonedAt, " . TestClock::SQL_NOW . "
             FROM generate_series(1, :count) AS n",
            [
                'onlineEntry' => OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED,
                'bytes' => $bytes,
                'uploadedAt' => $uploaded || $verified ? 1_792_000_001_000 : null,
                'verified' => $verified ? $bytes : null,
                'abandonedAt' => $abandoned ? TestClock::now()->format('Y-m-d H:i:s') : null,
                'count' => $count,
            ],
        );
    }

    private function abandonedAt(string $mediaId): mixed
    {
        return $this->connection()->fetchOne('SELECT abandoned_at FROM online_entry_media WHERE id = :id', ['id' => $mediaId]);
    }

    private static function uuidVersion(UuidInterface $uuid): null|int
    {
        $fields = $uuid->getFields();

        return $fields instanceof FieldsInterface ? $fields->getVersion() : null;
    }

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
            startsAt: TestClock::now()->modify('+30 days'),
            category: RoundCategory::Solo,
        );
        $entityManager->persist($round);
        $entityManager->persist(new OnlineRound(Uuid::uuid7(), $round, TestClock::now(), null));
        $entityManager->flush();

        return $round->id->toString();
    }

    private function connection(): Connection
    {
        return $this->browser->getContainer()->get(Connection::class);
    }
}
