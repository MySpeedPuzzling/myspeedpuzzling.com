<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OnlineRounds;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\OnlineRounds\S3QueryPresigner;
use SpeedPuzzling\Web\Value\OnlineMediaStoreLocation;

/**
 * Signature Version 4 in the query string - AWS' own example, and the upload URLs of the evidence: signed for their
 * exact type and length, so the store refuses any other upload (checked against a real MinIO on 2026-10-13: the right
 * upload 200, another length or type 403 SignatureDoesNotMatch).
 */
final class S3QueryPresignerTest extends TestCase
{
    /**
     * docs.aws.amazon.com/AmazonS3/latest/API/sigv4-query-string-auth.html - "Example: Presigned URL"
     */
    public function testAwsOwnExampleOfAPresignedUrl(): void
    {
        $url = (new S3QueryPresigner())->presign(
            new OnlineMediaStoreLocation('https://s3.amazonaws.com', 'us-east-1', 'examplebucket', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', pathStyle: false),
            'GET',
            'test.txt',
            [],
            new DateTimeImmutable('2013-05-24 00:00:00', new DateTimeZone('UTC')),
            86400,
        );

        self::assertSame(
            'https://examplebucket.s3.amazonaws.com/test.txt'
            . '?X-Amz-Algorithm=AWS4-HMAC-SHA256'
            . '&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request'
            . '&X-Amz-Date=20130524T000000Z'
            . '&X-Amz-Expires=86400'
            . '&X-Amz-SignedHeaders=host'
            . '&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
            $url,
        );
    }

    public function testAnUploadIsSignedForItsTypeAndLength(): void
    {
        $url = (new S3QueryPresigner())->presign(
            new OnlineMediaStoreLocation('http://localhost:19000', 'global', 'puzzle', 'access-key', 'secret-key'),
            'PUT',
            'online/competition/round/participant/media.jpg',
            ['Content-Type' => 'image/jpeg', 'Content-Length' => '182000'],
            new DateTimeImmutable('2026-10-13 12:00:00.250', new DateTimeZone('Europe/Prague')),
            300,
        );

        $parts = parse_url($url);
        self::assertIsArray($parts);
        self::assertSame('http', $parts['scheme'] ?? null);
        self::assertSame('localhost', $parts['host'] ?? null);
        self::assertSame(19000, $parts['port'] ?? null);
        // Path style: the bucket, then the key
        self::assertSame('/puzzle/online/competition/round/participant/media.jpg', $parts['path'] ?? null);

        parse_str($parts['query'] ?? '', $query);
        self::assertSame('AWS4-HMAC-SHA256', $query['X-Amz-Algorithm'] ?? null);
        self::assertSame('content-length;content-type;host', $query['X-Amz-SignedHeaders'] ?? null);
        // UTC, whatever the clock's zone
        self::assertSame('20261013T100000Z', $query['X-Amz-Date'] ?? null);
        self::assertSame('access-key/20261013/global/s3/aws4_request', $query['X-Amz-Credential'] ?? null);
        self::assertSame('300', $query['X-Amz-Expires'] ?? null);
        $signature = $query['X-Amz-Signature'] ?? null;
        self::assertIsString($signature);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
        self::assertStringEndsWith('&X-Amz-Signature=' . $signature, $url);
    }

    public function testAnotherLengthOrTypeIsAnotherSignature(): void
    {
        $signed = self::uploadSignature(['Content-Type' => 'image/jpeg', 'Content-Length' => '1000']);

        self::assertSame($signed, self::uploadSignature(['content-type' => 'image/jpeg', 'content-length' => ' 1000 ']));
        self::assertNotSame($signed, self::uploadSignature(['Content-Type' => 'image/jpeg', 'Content-Length' => '1001']));
        self::assertNotSame($signed, self::uploadSignature(['Content-Type' => 'application/octet-stream', 'Content-Length' => '1000']));
    }

    public function testTheDefaultPortIsNoPartOfTheHost(): void
    {
        $url = (new S3QueryPresigner())->presign(
            new OnlineMediaStoreLocation('https://store.example.org:443/base/', 'eu', 'evidence', 'key', 'secret'),
            'GET',
            '/online/a b.jpg',
            [],
            new DateTimeImmutable('2026-10-13 12:00:00', new DateTimeZone('UTC')),
            60,
        );

        self::assertStringStartsWith('https://store.example.org/base/evidence/online/a%20b.jpg?', $url);
    }

    public function testAPresignedUrlLivesAtMostAWeek(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new S3QueryPresigner())->presign(
            new OnlineMediaStoreLocation('https://s3.example.org', 'eu', 'bucket', 'key', 'secret'),
            'GET',
            'key',
            [],
            new DateTimeImmutable(),
            S3QueryPresigner::MAX_LIFETIME_SECONDS + 1,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private static function uploadSignature(array $headers): string
    {
        $url = (new S3QueryPresigner())->presign(
            new OnlineMediaStoreLocation('https://fsn1.your-objectstorage.com', 'fsn1', 'bucket', 'key', 'secret'),
            'PUT',
            'online/media.jpg',
            $headers,
            new DateTimeImmutable('2026-10-13 12:00:00', new DateTimeZone('UTC')),
            300,
        );
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $signature = $query['X-Amz-Signature'] ?? null;

        return is_string($signature) ? $signature : '';
    }
}
