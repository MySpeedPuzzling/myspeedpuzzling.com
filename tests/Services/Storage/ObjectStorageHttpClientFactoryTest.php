<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Storage;

use AsyncAws\Core\Configuration;
use AsyncAws\Core\Exception\Http\NetworkException;
use AsyncAws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Storage\ObjectStorageHttpClientFactory;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ObjectStorageHttpClientFactoryTest extends TestCase
{
    /** @var list<string> method + request body of every request that reached the transport */
    private array $requests = [];

    public function testFailedAttemptIsRetriedOnce(): void
    {
        $client = $this->s3Client([
            self::transportFailure(),
            new MockResponse('', ['http_code' => 200]),
        ]);

        $result = $client->headObject(['Bucket' => 'bucket', 'Key' => 'photo.jpg']);

        self::assertTrue($result->resolve());
        self::assertSame(['HEAD ', 'HEAD '], $this->requests);
    }

    public function testRetriedUploadSendsTheSameBytes(): void
    {
        $client = $this->s3Client([
            self::transportFailure(),
            new MockResponse('', ['http_code' => 200]),
        ]);

        $stream = fopen('php://temp', 'r+b');
        assert(is_resource($stream));
        fwrite($stream, 'image bytes');
        rewind($stream);

        $result = $client->putObject(['Bucket' => 'bucket', 'Key' => 'photo.jpg', 'Body' => $stream]);

        self::assertTrue($result->resolve());
        self::assertSame(['PUT image bytes', 'PUT image bytes'], $this->requests);
    }

    public function testPersistentOutageGivesUpAfterTheRetry(): void
    {
        $client = $this->s3Client([self::transportFailure(), self::transportFailure(), self::transportFailure()]);

        $result = $client->headObject(['Bucket' => 'bucket', 'Key' => 'photo.jpg']);

        try {
            $result->resolve();
            self::fail('A persistent outage must surface as a NetworkException');
        } catch (NetworkException) {
        }

        self::assertCount(1 + ObjectStorageHttpClientFactory::MAX_RETRIES, $this->requests);
    }

    public function testMissingObjectIsNotRetried(): void
    {
        $client = $this->s3Client([
            new MockResponse('', ['http_code' => 404]),
            new MockResponse('', ['http_code' => 200]),
        ]);

        self::assertFalse($client->objectExists(['Bucket' => 'bucket', 'Key' => 'missing.jpg'])->isSuccess());
        self::assertCount(1, $this->requests);
    }

    /**
     * The production failure, end to end on the real curl client: the server
     * accepts the connection and never answers. The idle timeout must be retried
     * on a new connection, and the whole operation must still give up.
     */
    public function testServerThatNeverAnswersIsRetriedOnAFreshConnection(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertNotFalse($server, (string) $errorMessage);
        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);

        $client = new S3Client(
            self::configuration('http://' . $address),
            httpClient: ObjectStorageHttpClientFactory::create(new InMemoryLogger(), self::withIdleTimeout(HttpClient::create(), 0.6)),
        );

        $startedAt = microtime(true);

        try {
            $client->headObject(['Bucket' => 'bucket', 'Key' => 'photo.jpg'])->resolve();
            self::fail('A server that never answers must surface as a NetworkException');
        } catch (NetworkException $exception) {
            self::assertStringContainsString('Idle timeout', $exception->getPrevious()?->getMessage() ?? '');
        }

        self::assertLessThan(5.0, microtime(true) - $startedAt, 'The retry budget must stay bounded');

        // Every attempt left one connection in the listen backlog
        $connections = 0;
        while (@stream_socket_accept($server, 0.2) !== false) {
            $connections++;
        }
        fclose($server);

        self::assertSame(1 + ObjectStorageHttpClientFactory::MAX_RETRIES, $connections);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function s3Client(array $responses): S3Client
    {
        $transport = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = $method . ' ' . self::readBody($options['body'] ?? '');

            $response = array_shift($responses);
            assert($response instanceof MockResponse);

            return $response;
        });

        return new S3Client(
            self::configuration('https://object-storage.test'),
            httpClient: ObjectStorageHttpClientFactory::create(new InMemoryLogger(), $transport),
        );
    }

    private static function configuration(string $endpoint): Configuration
    {
        return Configuration::create([
            Configuration::OPTION_REGION => 'fsn1',
            Configuration::OPTION_ENDPOINT => $endpoint,
            Configuration::OPTION_ACCESS_KEY_ID => 'key',
            Configuration::OPTION_SECRET_ACCESS_KEY => 'secret',
            Configuration::OPTION_PATH_STYLE_ENDPOINT => 'true',
        ]);
    }

    /**
     * A connection that breaks before any response byte arrives - what an idle
     * timeout during the initial wait turns into inside the retrying client.
     */
    private static function transportFailure(): MockResponse
    {
        return new MockResponse('', ['error' => 'Idle timeout reached']);
    }

    private static function readBody(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }

        assert($body instanceof \Closure);
        $content = '';

        while ('' !== $chunk = $body(16384)) {
            assert(is_string($chunk));
            $content .= $chunk;
        }

        return $content;
    }

    /**
     * Shortens the idle timeout the factory sets, so the real-socket test runs in
     * about a second.
     */
    private static function withIdleTimeout(HttpClientInterface $client, float $timeout): HttpClientInterface
    {
        return new class ($client, $timeout) implements HttpClientInterface {
            use DecoratorTrait;

            public function __construct(HttpClientInterface $client, private readonly float $timeout)
            {
                $this->client = $client;
            }

            /**
             * @param array<string, mixed> $options
             */
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                return $this->client->request($method, $url, ['timeout' => $this->timeout] + $options);
            }
        };
    }
}
