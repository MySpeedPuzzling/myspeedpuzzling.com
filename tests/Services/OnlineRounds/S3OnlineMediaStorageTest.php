<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OnlineRounds;

use AsyncAws\Core\Configuration;
use AsyncAws\S3\S3Client;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\OnlineEntry;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use SpeedPuzzling\Web\Services\OnlineRounds\S3OnlineMediaStorage;
use SpeedPuzzling\Web\Services\OnlineRounds\S3QueryPresigner;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The evidence in S3-compatible object storage: presigned uploads for the phones (the public endpoint, signed type and
 * length), presigned looks, and the server's HEAD and DELETE through the store's own client.
 */
final class S3OnlineMediaStorageTest extends TestCase
{
    private const string KEY = 'online/018d0050-0000-0000-0000-000000000001/018d0050-0000-0000-0000-000000000101/018d0050-0000-0000-0000-000000000201/019a0000-0000-7000-8000-000000000001.jpg';

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock(new DateTimeImmutable('2026-10-13 12:00:00.250', new DateTimeZone('UTC')));
    }

    public function testAnUploadIsPresignedForThePublicEndpointWithTheTypeAndLengthSigned(): void
    {
        $storage = $this->storage(new MockHttpClient());
        $upload = $storage->presignPut($this->media(OnlineMediaStore::Primary));

        self::assertSame(OnlineMediaStore::Primary, $upload->store);
        self::assertSame(['Content-Type' => 'image/jpeg', 'Content-Length' => '182000'], $upload->headers);
        self::assertSame(1_791_892_800_250 + S3OnlineMediaStorage::PUT_LIFETIME_SECONDS * 1000, $upload->expiresAtMs);
        self::assertStringStartsWith('http://localhost:19000/puzzle/' . self::KEY . '?', $upload->url);

        parse_str((string) parse_url($upload->url, PHP_URL_QUERY), $query);
        self::assertSame('content-length;content-type;host', $query['X-Amz-SignedHeaders'] ?? null);
        self::assertSame((string) S3OnlineMediaStorage::PUT_LIFETIME_SECONDS, $query['X-Amz-Expires'] ?? null);
        self::assertSame('primary-key/20261013/eu-central/s3/aws4_request', $query['X-Amz-Credential'] ?? null);
    }

    public function testAnUploadToTheSecondStoreIsPresignedForIt(): void
    {
        $upload = $this->storage(new MockHttpClient())->presignPut($this->media(OnlineMediaStore::Secondary));

        self::assertSame(OnlineMediaStore::Secondary, $upload->store);
        self::assertStringStartsWith('https://garage.example.org/evidence/' . self::KEY . '?', $upload->url);

        parse_str((string) parse_url($upload->url, PHP_URL_QUERY), $query);
        self::assertSame('second-key/20261013/garage/s3/aws4_request', $query['X-Amz-Credential'] ?? null);
    }

    public function testALookIsAShortLivedGet(): void
    {
        $url = $this->storage(new MockHttpClient())->presignGet($this->media(OnlineMediaStore::Primary), 120);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('host', $query['X-Amz-SignedHeaders'] ?? null);
        self::assertSame('120', $query['X-Amz-Expires'] ?? null);
    }

    public function testHeadAsksTheServersOwnEndpointForTheSize(): void
    {
        $requests = [];
        $storage = $this->storage(new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $method . ' ' . $url;

            return new MockResponse('', ['http_code' => 200, 'response_headers' => ['content-length' => '181990']]);
        }));

        self::assertSame(181_990, $storage->head($this->media(OnlineMediaStore::Primary)));
        self::assertSame(['HEAD http://minio.internal:9000/puzzle/' . self::KEY], $requests);
    }

    public function testAMissingObjectHasNoSize(): void
    {
        $storage = $this->storage(new MockHttpClient(new MockResponse('', ['http_code' => 404])));

        self::assertNull($storage->head($this->media(OnlineMediaStore::Primary)));
    }

    public function testDeleteGoesToTheMediasStore(): void
    {
        $requests = [];
        $storage = $this->storage(new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $method . ' ' . $url;

            return new MockResponse('', ['http_code' => 204]);
        }));

        $storage->delete($this->media(OnlineMediaStore::Secondary));

        self::assertSame(['DELETE https://garage.example.org/evidence/' . self::KEY], $requests);
    }

    private function storage(MockHttpClient $httpClient): S3OnlineMediaStorage
    {
        return new S3OnlineMediaStorage(
            new OnlineMediaStores(
                primaryClient: new S3Client([
                    Configuration::OPTION_REGION => 'eu-central',
                    Configuration::OPTION_ENDPOINT => 'http://minio.internal:9000',
                    Configuration::OPTION_ACCESS_KEY_ID => 'primary-key',
                    Configuration::OPTION_SECRET_ACCESS_KEY => 'primary-secret',
                    Configuration::OPTION_PATH_STYLE_ENDPOINT => 'true',
                ], null, $httpClient),
                primaryRegion: 'eu-central',
                primaryBucket: 'puzzle',
                primaryAccessKey: 'primary-key',
                primarySecretKey: 'primary-secret',
                primaryPublicEndpoint: 'http://localhost:19000',
                secondaryEndpoint: 'https://garage.example.org',
                secondaryRegion: 'garage',
                secondaryBucket: 'evidence',
                secondaryAccessKey: 'second-key',
                secondarySecretKey: 'second-secret',
                secondaryPathStyle: true,
                httpClient: $httpClient,
                logger: new NullLogger(),
            ),
            new S3QueryPresigner(),
            $this->clock,
        );
    }

    private function media(OnlineMediaStore $store): OnlineEntryMedia
    {
        $setUpAt = new DateTimeImmutable('2026-10-12 10:00:00', new DateTimeZone('UTC'));
        $competition = new Competition(
            id: Uuid::uuid7(),
            name: 'Cup',
            slug: 'cup',
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: null,
            locationCountryCode: null,
            dateFrom: $setUpAt,
            dateTo: $setUpAt,
            tag: null,
            isOnline: true,
            addedByPlayer: null,
            approvedAt: $setUpAt,
            approvedByPlayer: null,
            createdAt: $setUpAt,
        );
        $round = new CompetitionRound(id: Uuid::uuid7(), competition: $competition, name: 'Round', minutesLimit: 60, startsAt: $setUpAt, category: RoundCategory::Solo);
        $participant = new CompetitionParticipant(Uuid::uuid7(), 'Olivia', 'cz', $competition);
        $onlineEntry = new OnlineEntry(Uuid::uuid7(), new CompetitionParticipantRound(Uuid::uuid7(), $participant, $round), $setUpAt);

        return new OnlineEntryMedia(
            id: Uuid::fromString('019a0000-0000-7000-8000-000000000001'),
            onlineEntry: $onlineEntry,
            kind: OnlineMediaKind::EmptyMat,
            takenMs: 1_791_806_400_000,
            store: $store,
            objectKey: self::KEY,
            contentType: 'image/jpeg',
            declaredBytes: 182_000,
            createdAt: $setUpAt,
        );
    }
}
