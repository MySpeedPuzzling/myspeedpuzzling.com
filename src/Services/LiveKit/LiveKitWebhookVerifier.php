<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\LiveKit;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\LiveKitWebhookRejected;

/**
 * Checks that a request to `POST /-/livekit-webhook` is a webhook of our LiveKit server (contract phase-1b.md §5):
 * LiveKit signs every webhook with a JWT in the `Authorization` header - HS256 with LIVEKIT_API_SECRET, issued by
 * LIVEKIT_API_KEY, valid a few minutes - whose `sha256` claim is the base64 of the SHA-256 of the exact body. A token of
 * another key, an expired one, or a body that is not the one signed is rejected (LiveKitWebhookRejected).
 *
 * firebase/php-jwt reads its clock and leeway from statics - they are set for the one decode() only (ClockInterface
 * keeps tests deterministic, the worker's next request finds them as they were).
 */
final readonly class LiveKitWebhookVerifier
{
    public const int LEEWAY_SECONDS = 60;
    // LiveKit's events are a few kilobytes
    public const int MAX_BODY_BYTES = 1024 * 1024;

    public function __construct(
        private LiveKitSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed> the event (the protocol's WebhookEvent)
     *
     * @throws LiveKitWebhookRejected
     */
    public function verify(string $body, null|string $authorization): array
    {
        if ($this->settings->isAvailable() === false) {
            throw LiveKitWebhookRejected::because('no LiveKit server is configured');
        }

        $token = trim((string) $authorization);

        if (str_starts_with(strtolower($token), 'bearer ')) {
            $token = trim(substr($token, 7));
        }

        if ($token === '') {
            throw LiveKitWebhookRejected::because('no Authorization header');
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw LiveKitWebhookRejected::because('the body is too large');
        }

        $previousLeeway = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;
        JWT::$leeway = self::LEEWAY_SECONDS;
        JWT::$timestamp = $this->clock->now()->getTimestamp();

        try {
            $claims = JWT::decode($token, new Key($this->settings->apiSecret(), LiveKitTokens::ALGORITHM));
        } catch (\Throwable $e) {
            // Malformed, another secret, another algorithm, expired, not valid yet
            throw LiveKitWebhookRejected::because('the signature is not valid', $e);
        } finally {
            JWT::$leeway = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }

        if (($claims->iss ?? null) !== $this->settings->apiKey()) {
            throw LiveKitWebhookRejected::because('signed for another API key');
        }

        // firebase/php-jwt checks the expiry only when there is one - a signature valid forever is none of LiveKit's
        if (!is_int($claims->exp ?? null)) {
            throw LiveKitWebhookRejected::because('the signature never expires');
        }

        $signedHash = $claims->sha256 ?? null;
        $signedHash = is_string($signedHash) ? base64_decode($signedHash, true) : false;

        if ($signedHash === false || hash_equals($signedHash, hash('sha256', $body, true)) === false) {
            throw LiveKitWebhookRejected::because('the body is not the one signed');
        }

        $event = json_decode($body, true);

        if (!is_array($event) || ($event !== [] && array_is_list($event))) {
            throw LiveKitWebhookRejected::because('the body is no JSON object');
        }

        /** @var array<string, mixed> $event */
        return $event;
    }
}
