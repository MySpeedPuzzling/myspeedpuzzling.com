<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\ClientInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\InvalidAppleServerNotification;
use SpeedPuzzling\Web\Value\AppleSignInEvent;
use SpeedPuzzling\Web\Value\AppleSignInEventType;

/**
 * Verifies a Sign in with Apple server-to-server notification: Apple POSTs
 * `{"payload": "<JWT>"}`, an RS256 token signed with the same keys as the
 * id_token (https://appleid.apple.com/auth/keys) whose `events` claim holds
 * one event - as a JSON string (observed) or an object (documented); both are
 * read.
 *
 * The endpoint is public, so every cheap check runs before Apple's JWKS is
 * fetched, and the JWKS is cached: refetched only for a key id we have not
 * seen, and then at most once per REFETCH_INTERVAL_SECONDS - a flood of forged
 * tokens can never turn into a flood of requests to Apple (which would put the
 * login callback, fetching the same keys, at risk).
 */
final readonly class AppleServerNotificationVerifier
{
    public const string JWKS_CACHE_KEY = 'apple_sign_in_jwks';
    private const string APPLE_ISSUER = 'https://appleid.apple.com';
    private const string JWKS_URL = 'https://appleid.apple.com/auth/keys';
    private const int REFETCH_INTERVAL_SECONDS = 300;
    private const int JWKS_TTL_SECONDS = 86400;

    public function __construct(
        private ClientInterface $httpClient,
        private CacheItemPoolInterface $socialLoginStateCache,
        private ClockInterface $clock,
        private string $appleClientId,
        private string $appleAppId,
    ) {
    }

    /**
     * @throws InvalidAppleServerNotification
     */
    public function verify(string $requestBody): AppleSignInEvent
    {
        $jwt = self::payloadFrom($requestBody);
        [$header, $unverifiedClaims] = self::unverifiedParts($jwt);

        if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null) || $header['kid'] === '') {
            throw new InvalidAppleServerNotification('Unexpected token header.');
        }

        $this->assertIssuedToUs($unverifiedClaims);

        try {
            $verified = JWT::decode($jwt, $this->appleKeys($header['kid']));
        } catch (\Throwable $exception) {
            // Bad signature, expired, not yet valid, ... - all mean "not Apple's"
            throw new InvalidAppleServerNotification('Token verification failed.', previous: $exception);
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode((string) json_encode($verified), associative: true);
        // Checked again on the verified claims - the pre-check read unverified bytes
        $this->assertIssuedToUs($claims);

        return self::event($claims['events'] ?? null);
    }

    private static function payloadFrom(string $requestBody): string
    {
        try {
            $body = json_decode($requestBody, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidAppleServerNotification('Body is not JSON.', previous: $exception);
        }

        $jwt = is_array($body) ? ($body['payload'] ?? null) : null;

        if (!is_string($jwt) || $jwt === '') {
            throw new InvalidAppleServerNotification('Body has no payload.');
        }

        return $jwt;
    }

    /**
     * @return array{0: array<mixed>, 1: array<mixed>}
     */
    private static function unverifiedParts(string $jwt): array
    {
        $segments = explode('.', $jwt);

        if (count($segments) !== 3) {
            throw new InvalidAppleServerNotification('Payload is not a JWT.');
        }

        $parts = [];

        foreach ([$segments[0], $segments[1]] as $segment) {
            $decoded = json_decode((string) base64_decode(strtr($segment, '-_', '+/'), true), associative: true);

            if (!is_array($decoded)) {
                throw new InvalidAppleServerNotification('Payload is not a JWT.');
            }

            $parts[] = $decoded;
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * `aud` of these tokens is the PRIMARY App ID (bundle id) the Services ID
     * is grouped under - not the Services ID the id_token carries. Both are
     * accepted; empty configuration accepts nothing.
     *
     * @param array<mixed> $claims
     */
    private function assertIssuedToUs(array $claims): void
    {
        if (($claims['iss'] ?? null) !== self::APPLE_ISSUER) {
            throw new InvalidAppleServerNotification('Unexpected issuer.');
        }

        $allowedAudiences = array_values(array_filter([$this->appleAppId, $this->appleClientId], static fn (string $id): bool => $id !== ''));
        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];

        if (array_intersect($allowedAudiences, array_filter($audiences, 'is_string')) === []) {
            throw new InvalidAppleServerNotification('Token was not issued to this app.');
        }
    }

    /**
     * @return array<string, Key>
     */
    private function appleKeys(string $keyId): array
    {
        $item = $this->socialLoginStateCache->getItem(self::JWKS_CACHE_KEY);
        $cached = $item->isHit() ? $item->get() : null;
        $now = $this->clock->now()->getTimestamp();

        if (is_array($cached) && is_array($cached['jwks'] ?? null) && is_int($cached['fetchedAt'] ?? null)) {
            /** @var array<string, mixed> $cachedJwks */
            $cachedJwks = $cached['jwks'];
            $keys = self::parseKeys($cachedJwks);

            if (isset($keys[$keyId]) || $now - $cached['fetchedAt'] < self::REFETCH_INTERVAL_SECONDS) {
                return $keys;
            }
        }

        try {
            $response = $this->httpClient->request('GET', self::JWKS_URL, ['timeout' => 5]);
            $jwks = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new InvalidAppleServerNotification('Could not fetch Apple keys.', previous: $exception);
        }

        if (!is_array($jwks)) {
            throw new InvalidAppleServerNotification('Apple keys are not a JWKS.');
        }

        /** @var array<string, mixed> $jwks */
        $keys = self::parseKeys($jwks);

        $item->set(['jwks' => $jwks, 'fetchedAt' => $now]);
        $item->expiresAfter(self::JWKS_TTL_SECONDS);
        $this->socialLoginStateCache->save($item);

        return $keys;
    }

    /**
     * RS256 pinned: a key Apple publishes without `alg` still only verifies
     * RS256, and JWT::decode refuses a header alg that differs from the key's.
     *
     * @param array<string, mixed> $jwks
     * @return array<string, Key>
     */
    private static function parseKeys(array $jwks): array
    {
        try {
            $keys = JWK::parseKeySet($jwks, 'RS256');
        } catch (\Throwable $exception) {
            throw new InvalidAppleServerNotification('Apple keys are not a JWKS.', previous: $exception);
        }

        return array_filter($keys, static fn (Key $key): bool => $key->getAlgorithm() === 'RS256');
    }

    private static function event(mixed $events): AppleSignInEvent
    {
        if (is_string($events)) {
            $events = json_decode($events, associative: true);
        }

        if (!is_array($events)) {
            throw new InvalidAppleServerNotification('Token carries no event.');
        }

        $type = $events['type'] ?? null;
        $sub = $events['sub'] ?? null;

        if (!is_string($type) || !is_string($sub) || $sub === '') {
            throw new InvalidAppleServerNotification('Event has no type or subject.');
        }

        return new AppleSignInEvent(AppleSignInEventType::fromNotification($type), $type, $sub);
    }
}
