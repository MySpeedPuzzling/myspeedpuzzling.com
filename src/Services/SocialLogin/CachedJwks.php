<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use GuzzleHttp\ClientInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\JwksUnavailable;

/**
 * A provider's published signing keys (JWKS), fetched through the
 * test-mockable social-login HTTP client and cached in the social login
 * cache pool - shared by Apple's server-to-server notifications and
 * Microsoft's id_tokens so the refetch throttle has one implementation.
 *
 * Keys are cached for a day and refetched early only for a key id we have not
 * seen (key rollover), and then at most once per REFETCH_INTERVAL_SECONDS: a
 * flood of tokens with made-up key ids can never turn into a flood of
 * requests to the provider.
 */
final readonly class CachedJwks
{
    public const int REFETCH_INTERVAL_SECONDS = 300;
    private const int JWKS_TTL_SECONDS = 86400;

    public function __construct(
        private ClientInterface $httpClient,
        private CacheItemPoolInterface $socialLoginStateCache,
        private ClockInterface $clock,
    ) {
    }

    /**
     * RS256 keys only, indexed by key id. The caller hands the result to
     * JWT::decode(), which picks the key named in the token header.
     *
     * @return array<string, Key>
     *
     * @throws JwksUnavailable
     */
    public function rs256Keys(string $cacheKey, string $url, string $keyId): array
    {
        $item = $this->socialLoginStateCache->getItem($cacheKey);
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
            $response = $this->httpClient->request('GET', $url, ['timeout' => 5]);
            $jwks = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new JwksUnavailable('Could not fetch the provider keys.', previous: $exception);
        }

        if (!is_array($jwks)) {
            throw new JwksUnavailable('The provider keys are not a JWKS.');
        }

        /** @var array<string, mixed> $jwks */
        $keys = self::parseKeys($jwks);

        $item->set(['jwks' => $jwks, 'fetchedAt' => $now]);
        $item->expiresAfter(self::JWKS_TTL_SECONDS);
        $this->socialLoginStateCache->save($item);

        return $keys;
    }

    /**
     * RS256 pinned: a key published without `alg` still only verifies RS256,
     * and JWT::decode refuses a header alg that differs from the key's.
     *
     * @param array<string, mixed> $jwks
     * @return array<string, Key>
     *
     * @throws JwksUnavailable
     */
    private static function parseKeys(array $jwks): array
    {
        try {
            $keys = JWK::parseKeySet($jwks, 'RS256');
        } catch (\Throwable $exception) {
            throw new JwksUnavailable('The provider keys are not a JWKS.', previous: $exception);
        }

        return array_filter($keys, static fn (Key $key): bool => $key->getAlgorithm() === 'RS256');
    }
}
