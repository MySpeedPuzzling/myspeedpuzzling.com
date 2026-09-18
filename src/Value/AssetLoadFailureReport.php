<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One sendBeacon report from the inline asset-failure script in base.html.twig,
 * parsed defensively: the body is whatever a browser, a bot or a stale page
 * sent. Pages rendered before the script learnt to describe its self-heal send
 * the original four fields only (scriptVersion 1).
 */
final readonly class AssetLoadFailureReport
{
    private const int MAX_STRING_LENGTH = 500;

    /**
     * What the self-heal's forced refetch observed: 'intact' / 'corrupt' (bytes
     * checked against the SRI hash), 'fetched' (delivered, not verified),
     * 'unreachable' (network error), 'timeout' or 'http-<status>'.
     */
    private const string REFETCH_PATTERN = '~^(intact|corrupt|fetched|unreachable|timeout|http-\d{1,3})$~';

    public function __construct(
        public string $assetUrl,
        public null|string $page,
        public bool $serviceWorkerControlled,
        public bool $retry,
        public null|string $userAgent,
        public int $scriptVersion = 1,
        public null|bool $healing = null,
        public null|string $refetch = null,
        public null|int $renderedAt = null,
        public bool $webdriver = false,
    ) {
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromBeacon(array $payload, null|string $userAgent): null|self
    {
        $assetUrl = $payload['url'] ?? null;

        if (!is_string($assetUrl) || !str_contains($assetUrl, '/build/')) {
            return null;
        }

        $page = $payload['page'] ?? null;
        $version = $payload['v'] ?? null;
        $healing = $payload['healing'] ?? null;
        $refetch = $payload['refetch'] ?? null;
        $renderedAt = $payload['rendered'] ?? null;

        return new self(
            assetUrl: mb_substr($assetUrl, 0, self::MAX_STRING_LENGTH),
            page: is_string($page) ? mb_substr($page, 0, self::MAX_STRING_LENGTH) : null,
            serviceWorkerControlled: ($payload['controlled'] ?? null) === true,
            retry: ($payload['retry'] ?? null) === true,
            userAgent: $userAgent === null ? null : mb_substr($userAgent, 0, self::MAX_STRING_LENGTH),
            scriptVersion: is_int($version) && $version > 1 ? $version : 1,
            healing: is_bool($healing) ? $healing : null,
            refetch: is_string($refetch) && preg_match(self::REFETCH_PATTERN, $refetch) === 1 ? $refetch : null,
            renderedAt: is_int($renderedAt) && $renderedAt > 0 ? $renderedAt : null,
            webdriver: ($payload['webdriver'] ?? null) === true,
        );
    }

    /**
     * The forced refetch received a response body - the asset exists wherever
     * that request was answered, even if this container does not have it.
     */
    public function refetchReceivedBytes(): bool
    {
        return in_array($this->refetch, ['intact', 'corrupt', 'fetched'], true);
    }
}
