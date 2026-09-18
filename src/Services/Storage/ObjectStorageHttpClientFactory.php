<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Storage;

use AsyncAws\Core\HttpClient\AwsRetryStrategy;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP client for the S3Client talking to Hetzner Object Storage.
 *
 * Hetzner answers the odd request - two in three of them PUTs - later than the
 * idle timeout. 2026-08-19 → 09-18: 179 "Idle timeout reached" failures, never a
 * refused connection or a DNS error, mostly one at a time, and every spooled
 * upload among them went through on the drain's first retry minutes later. With
 * no retry at all, each one failed a read or parked an upload in the spool, so a
 * failed attempt is now retried once, right away, on a fresh connection.
 *
 * The limits keep a real outage cheap: against a storage that stops answering, an
 * operation gives up after about 2 × IDLE_TIMEOUT_SECONDS - not minutes - and the
 * upload spool still catches whatever is left. The retry's idle clock starts when it is scheduled, so the
 * pause before it comes out of its IDLE_TIMEOUT_SECONDS, not on top.
 *
 * AsyncAws' own default client retries too (3 attempts, 1 s → 2 s backoff) but
 * without timeouts; this keeps its retry strategy - transport errors for
 * idempotent methods, AWS throttling and 5xx - on a budget sized for a
 * user-facing request. Every S3 operation used here is idempotent (GET, HEAD,
 * PUT, DELETE; CopyObject is a PUT), and AsyncAws request bodies are replayable,
 * so a retried PUT sends the same bytes.
 */
final class ObjectStorageHttpClientFactory
{
    /** Seconds without a byte from Hetzner before an attempt is abandoned */
    public const float IDLE_TIMEOUT_SECONDS = 3.0;

    /** Hard cap for a single attempt, uploads included */
    public const float MAX_DURATION_SECONDS = 10.0;

    public const int MAX_RETRIES = 1;

    public const int RETRY_DELAY_MS = 200;

    public static function create(LoggerInterface $logger, null|HttpClientInterface $transport = null): HttpClientInterface
    {
        $transport ??= HttpClient::create();

        return new RetryableHttpClient(
            $transport->withOptions([
                'timeout' => self::IDLE_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ]),
            new AwsRetryStrategy(
                delayMs: self::RETRY_DELAY_MS,
                multiplier: 2.0,
                maxDelayMs: 1000,
            ),
            self::MAX_RETRIES,
            $logger,
        );
    }
}
