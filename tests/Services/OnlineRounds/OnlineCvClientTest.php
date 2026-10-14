<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OnlineRounds;

use AsyncAws\Core\Configuration;
use DateTimeImmutable;
use AsyncAws\S3\S3Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineCvClient;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineCvSettings;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use SpeedPuzzling\Web\Services\OnlineRounds\S3QueryPresigner;
use SpeedPuzzling\Web\Value\OnlineCvAnswer;
use SpeedPuzzling\Web\Value\OnlineCvRequest;
use SpeedPuzzling\Web\Value\OnlineFinishCheckVerdict;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The app's side of the CV worker (OnlineCvClient - Phase 3): which answers are a verdict, which are asked again later
 * and which are given up; a verdict read defensively; and the host rule - a URL handed to the worker names one of the
 * configured stores' server-side hosts or is never sent (the worker refuses any other host too: an internal service
 * fetching URLs is an SSRF risk).
 */
final class OnlineCvClientTest extends TestCase
{
    private const string PRIMARY = 'http://minio.internal:9000/puzzle/online/a.jpg?X-Amz-Signature=1';
    private const string SECONDARY = 'https://garage.example.org/evidence/online/a.jpg?X-Amz-Signature=2';

    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    public function testTheStoresServerSideHostsAreTheOnlyOnes(): void
    {
        $stores = self::stores();

        self::assertSame(['minio.internal:9000', 'garage.example.org'], $stores->serverHosts());
        self::assertSame('http://minio.internal:9000', $stores->serverLocation(OnlineMediaStore::Primary)->endpoint);
        self::assertSame('http://localhost:19000', $stores->publicLocation(OnlineMediaStore::Primary)->endpoint, 'the browser\'s endpoint stays as it was');
        self::assertSame('https://garage.example.org', $stores->serverLocation(OnlineMediaStore::Secondary)->endpoint);
    }

    public function testWithoutAServerEndpointThePublicOneIsUsed(): void
    {
        self::assertSame(['localhost:19000'], self::stores(serverEndpoint: '', secondary: false)->serverHosts());
    }

    /**
     * The hosts are those the presigned URLs name: a store addressed by host (`bucket.host`) and an endpoint written
     * with its scheme's own port (`:443`) - the URLs made for them reach the worker (refused for a spelling before)
     */
    public function testAStoreAddressedByHostAndAnEndpointWithItsOwnPortAreTheHostsTheUrlsName(): void
    {
        $stores = self::stores(serverEndpoint: 'https://s3.example.com:443', secondaryEndpoint: 'https://Garage.example.org:443', secondaryPathStyle: false);

        self::assertSame(['s3.example.com', 'evidence.garage.example.org'], $stores->serverHosts());

        $presigner = new S3QueryPresigner();
        $now = new DateTimeImmutable('2026-10-14 08:00:00');
        $primary = $presigner->presign($stores->serverLocation(OnlineMediaStore::Primary), 'GET', 'online/a.jpg', [], $now, 300);
        $secondary = $presigner->presign($stores->serverLocation(OnlineMediaStore::Secondary), 'GET', 'online/b.jpg', [], $now, 300);
        self::assertStringStartsWith('https://s3.example.com/puzzle/online/a.jpg?', $primary);
        self::assertStringStartsWith('https://evidence.garage.example.org/online/b.jpg?', $secondary);

        $answer = $this->client(new MockResponse((string) json_encode(['verdict' => 'complete', 'version' => 'w1-c1'])), $stores)
            ->check(new OnlineCvRequest($primary, $secondary, null, 1000));

        self::assertSame(OnlineCvAnswer::VERDICT, $answer->kind);
        self::assertCount(1, $this->requests);
    }

    public function testAUrlsHostIsReadWithoutItsSchemesOwnPort(): void
    {
        self::assertSame('minio', OnlineMediaStores::hostOf('http://MINIO:80/puzzle/a.jpg'));
        self::assertSame('minio:9000', OnlineMediaStores::hostOf('http://minio:9000/puzzle/a.jpg'));
        self::assertSame('s3.example.com', OnlineMediaStores::hostOf('https://s3.example.com:443/a.jpg'));
        self::assertSame('s3.example.com', OnlineMediaStores::hostOf('https://s3.example.com/a.jpg'));
        self::assertSame('s3.example.com:80', OnlineMediaStores::hostOf('https://s3.example.com:80/a.jpg'));
        self::assertSame('s3.example.com:443', OnlineMediaStores::hostOf('http://s3.example.com:443/a.jpg'));
        self::assertNull(OnlineMediaStores::hostOf('not a url'));
    }

    public function testAVerdictIsRead(): void
    {
        $answer = $this->client(new MockResponse((string) json_encode([
            'verdict' => 'gaps',
            'confidence' => 0.734,
            'gaps' => [['x' => 812, 'y' => 403, 'w' => 41, 'h' => 38], ['x' => 1, 'y' => 1, 'w' => 0, 'h' => 5], ['x' => 'a', 'y' => 1, 'w' => 2, 'h' => 2]],
            'reason' => '1 hole(s) the colour of the mat',
            'method' => 'holes+grid',
            'version' => 'w1-c1',
            'ms' => 441,
            'checkMs' => 376,
            'image' => ['width' => 1920, 'height' => 1080],
            'inputs' => ['emptyMat' => 'used', 'reference' => 'unreadable'],
            'details' => ['holes' => ['verdict' => 'gaps'], 'grid' => null],
        ])))->check(self::request(self::PRIMARY, self::SECONDARY, null));

        self::assertSame(OnlineCvAnswer::VERDICT, $answer->kind);
        self::assertSame(OnlineFinishCheckVerdict::Gaps, $answer->verdict);
        self::assertSame(0.734, $answer->confidence);
        self::assertSame([['x' => 812, 'y' => 403, 'w' => 41, 'h' => 38]], $answer->gaps, 'only boxes that are boxes');
        self::assertSame(['w1-c1', 'holes+grid', 441, 1920, 1080], [$answer->version, $answer->method, $answer->ms, $answer->imageWidth, $answer->imageHeight]);
        self::assertSame(['emptyMat' => 'used', 'reference' => 'unreadable'], $answer->details['inputs'] ?? null);

        self::assertCount(1, $this->requests);
        self::assertSame(['POST', 'http://online-cv.test:8000/check'], [$this->requests[0]['method'], $this->requests[0]['url']]);
        self::assertSame(
            ['finishUrl' => self::PRIMARY, 'emptyMatUrl' => self::SECONDARY, 'referenceUrl' => null, 'pieceCount' => 1000],
            json_decode($this->requests[0]['body'], true),
        );
    }

    public function testAnAnswerThatIsNoVerdictIsGivenUp(): void
    {
        foreach (
            [
            ['verdict' => 'failed', 'version' => 'w1-c1'],
            ['verdict' => 'maybe', 'version' => 'w1-c1'],
            ['verdict' => 'complete'],
            ['verdict' => 'complete', 'version' => str_repeat('v', 33)],
            ] as $json
        ) {
            $answer = $this->client(new MockResponse((string) json_encode($json)))->check(self::request(self::PRIMARY));

            self::assertSame([OnlineCvAnswer::FAILED, 'invalid_answer'], [$answer->kind, $answer->error], (string) json_encode($json));
        }

        $answer = $this->client(new MockResponse('<html>'))->check(self::request(self::PRIMARY));
        self::assertSame([OnlineCvAnswer::FAILED, 'invalid_answer'], [$answer->kind, $answer->error]);
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>, string, string}>
     */
    public static function errors(): iterable
    {
        yield 'a store the worker could not reach' => [502, ['error' => 'download_failed', 'input' => 'emptyMat', 'status' => 503], OnlineCvAnswer::RETRY, 'download_failed:emptyMat'];
        yield 'busy' => [503, ['error' => 'busy'], OnlineCvAnswer::RETRY, 'busy'];
        yield 'a proxy timeout' => [504, [], OnlineCvAnswer::RETRY, 'http_504'];
        yield 'the photo is gone' => [422, ['error' => 'finish_unavailable', 'status' => 404], OnlineCvAnswer::FAILED, 'finish_unavailable'];
        yield 'not an image' => [422, ['error' => 'finish_unreadable'], OnlineCvAnswer::FAILED, 'finish_unreadable'];
        yield 'a host it may not fetch' => [403, ['error' => 'url_not_allowed', 'input' => 'reference'], OnlineCvAnswer::FAILED, 'url_not_allowed:reference'];
        yield 'its own error' => [500, ['error' => 'check_failed'], OnlineCvAnswer::FAILED, 'check_failed'];
        yield 'an odd code' => [500, ['error' => 'Something <script>'], OnlineCvAnswer::FAILED, 'http_500'];
    }

    /**
     * @param array<string, mixed> $json
     */
    #[DataProvider('errors')]
    public function testErrorsAreAskedAgainOrGivenUp(int $status, array $json, string $kind, string $error): void
    {
        $answer = $this->client(new MockResponse((string) json_encode($json), ['http_code' => $status]))->check(self::request(self::PRIMARY));

        self::assertSame([$kind, $error], [$answer->kind, $answer->error]);
    }

    public function testAWorkerThatDoesNotAnswerIsAskedAgainLater(): void
    {
        $client = new OnlineCvClient(new OnlineCvSettings('http://online-cv.test:8000'), self::stores(), new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        }));

        $answer = $client->check(self::request(self::PRIMARY));

        self::assertSame([OnlineCvAnswer::RETRY, 'unreachable'], [$answer->kind, $answer->error]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignUrls(): iterable
    {
        yield 'another host' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'the store on another port' => ['http://minio.internal:9001/puzzle/a.jpg'];
        yield 'the browser\'s endpoint' => ['http://localhost:19000/puzzle/a.jpg'];
        yield 'the store as a subdomain' => ['http://minio.internal.evil.example:9000/puzzle/a.jpg'];
        yield 'no URL' => ['not a url'];
    }

    #[DataProvider('foreignUrls')]
    public function testAUrlOfAnotherHostIsNeverSent(string $url): void
    {
        foreach ([self::request($url), self::request(self::PRIMARY, $url), self::request(self::PRIMARY, null, $url)] as $request) {
            $answer = $this->client(new MockResponse('{}'))->check($request);

            self::assertSame([OnlineCvAnswer::FAILED, 'url_not_allowed'], [$answer->kind, $answer->error]);
        }

        self::assertSame([], $this->requests, 'nothing reached the worker');
    }

    private function client(MockResponse $response, null|OnlineMediaStores $stores = null): OnlineCvClient
    {
        return new OnlineCvClient(
            new OnlineCvSettings(' http://online-cv.test:8000/ '),
            $stores ?? self::stores(),
            new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
                $body = $options['body'] ?? '';
                $this->requests[] = ['method' => $method, 'url' => $url, 'body' => is_string($body) ? $body : ''];

                return $response;
            }),
        );
    }

    private static function request(string $finishUrl, null|string $emptyMatUrl = null, null|string $referenceUrl = null): OnlineCvRequest
    {
        return new OnlineCvRequest($finishUrl, $emptyMatUrl, $referenceUrl, 1000);
    }

    private static function stores(
        string $serverEndpoint = 'http://minio.internal:9000',
        bool $secondary = true,
        string $secondaryEndpoint = 'https://garage.example.org',
        bool $secondaryPathStyle = true,
    ): OnlineMediaStores {
        return new OnlineMediaStores(
            primaryClient: new S3Client([
                Configuration::OPTION_REGION => 'eu-central',
                Configuration::OPTION_ENDPOINT => 'http://minio.internal:9000',
                Configuration::OPTION_ACCESS_KEY_ID => 'primary-key',
                Configuration::OPTION_SECRET_ACCESS_KEY => 'primary-secret',
                Configuration::OPTION_PATH_STYLE_ENDPOINT => 'true',
            ], null, new MockHttpClient()),
            primaryRegion: 'eu-central',
            primaryBucket: 'puzzle',
            primaryAccessKey: 'primary-key',
            primarySecretKey: 'primary-secret',
            primaryPublicEndpoint: 'http://localhost:19000',
            secondaryEndpoint: $secondary ? $secondaryEndpoint : '',
            secondaryRegion: 'garage',
            secondaryBucket: 'evidence',
            secondaryAccessKey: 'second-key',
            secondarySecretKey: 'second-secret',
            secondaryPathStyle: $secondaryPathStyle,
            httpClient: new MockHttpClient(),
            logger: new NullLogger(),
            primaryServerEndpoint: $serverEndpoint,
        );
    }
}
