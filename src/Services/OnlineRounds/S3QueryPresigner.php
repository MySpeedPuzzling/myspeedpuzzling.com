<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SpeedPuzzling\Web\Value\OnlineMediaStoreLocation;

/**
 * A presigned S3 URL - AWS Signature Version 4 in the query string ("Authenticating Requests: Using Query
 * Parameters"), for one object, one method and the headers the request must carry.
 *
 * Written here rather than AsyncAws' presign() on purpose: AsyncAws' SignerV4 never puts `Content-Type` or
 * `Content-Length` into a presigned URL's signed headers (its BLACKLIST_HEADERS), and the evidence uploads must be
 * signed for both (docs/features/online-competitions/README.md §5.11) - the store then refuses a PUT of any other
 * size or type, which is what caps an upload. The payload itself stays unsigned (`UNSIGNED-PAYLOAD`), as in every
 * presigned S3 URL.
 *
 * Pinned by tests/Services/OnlineRounds/S3QueryPresignerTest.php (AWS' own example of a presigned URL and the signed
 * headers of an upload).
 */
final readonly class S3QueryPresigner
{
    // AWS caps a presigned URL at seven days
    public const int MAX_LIFETIME_SECONDS = 604_800;

    private const string ALGORITHM = 'AWS4-HMAC-SHA256';

    /**
     * @param array<string, string> $signedHeaders headers the request must send with exactly these values (besides Host)
     */
    public function presign(
        OnlineMediaStoreLocation $location,
        string $method,
        string $key,
        array $signedHeaders,
        DateTimeImmutable $now,
        int $lifetimeSeconds,
    ): string {
        if ($lifetimeSeconds < 1 || $lifetimeSeconds > self::MAX_LIFETIME_SECONDS) {
            throw new InvalidArgumentException('A presigned URL lives between one second and seven days.');
        }

        $endpoint = parse_url($location->endpoint);

        if ($endpoint === false || !isset($endpoint['scheme'], $endpoint['host'])) {
            throw new InvalidArgumentException(sprintf('The object storage endpoint "%s" is not a URL.', $location->endpoint));
        }

        $scheme = strtolower($endpoint['scheme']);
        $host = strtolower($endpoint['host']);
        $port = $endpoint['port'] ?? null;

        // The Host header a browser sends: the port only when it is not the scheme's own
        if ($port !== null && !($scheme === 'https' && $port === 443) && !($scheme === 'http' && $port === 80)) {
            $host .= ':' . $port;
        }

        // S3 does not double-encode the path: every segment encoded once, the slashes kept
        $basePath = rtrim($endpoint['path'] ?? '', '/');
        $encodedKey = implode('/', array_map(rawurlencode(...), explode('/', ltrim($key, '/'))));

        if ($location->pathStyle) {
            $path = $basePath . '/' . rawurlencode($location->bucket) . '/' . $encodedKey;
        } else {
            $host = $location->bucket . '.' . $host;
            $path = $basePath . '/' . $encodedKey;
        }

        $utc = $now->setTimezone(new DateTimeZone('UTC'));
        $amzDate = $utc->format('Ymd\THis\Z');
        $day = $utc->format('Ymd');
        $scope = $day . '/' . $location->region . '/s3/aws4_request';

        $headers = ['host' => $host];

        foreach ($signedHeaders as $name => $value) {
            $headers[strtolower($name)] = trim((string) preg_replace('/\s+/', ' ', $value));
        }

        ksort($headers, SORT_STRING);
        $signedHeaderNames = implode(';', array_keys($headers));

        $query = [
            'X-Amz-Algorithm' => self::ALGORITHM,
            'X-Amz-Credential' => $location->accessKey . '/' . $scope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $lifetimeSeconds,
            'X-Amz-SignedHeaders' => $signedHeaderNames,
        ];
        ksort($query, SORT_STRING);

        $canonicalQuery = implode('&', array_map(
            static fn (string $name, string $value): string => rawurlencode($name) . '=' . rawurlencode($value),
            array_keys($query),
            $query,
        ));

        $canonicalHeaders = '';

        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $path,
            $canonicalQuery,
            // Every canonical header ends with a new line - the block is followed by an empty line
            $canonicalHeaders,
            $signedHeaderNames,
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = 'AWS4' . $location->secretKey;

        foreach ([$day, $location->region, 's3', 'aws4_request'] as $scopePart) {
            $signingKey = hash_hmac('sha256', $scopePart, $signingKey, true);
        }

        return $scheme . '://' . $host . $path . '?' . $canonicalQuery . '&X-Amz-Signature=' . hash_hmac('sha256', $stringToSign, $signingKey);
    }
}
