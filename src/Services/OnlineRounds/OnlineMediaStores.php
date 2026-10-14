<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use AsyncAws\Core\Configuration;
use AsyncAws\S3\S3Client;
use LogicException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use SpeedPuzzling\Web\Value\OnlineMediaStoreLocation;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The object stores online competition evidence goes to (docs/features/online-competitions/README.md §5.11):
 * - primary = the site's own bucket (S3_*). The server talks to it through the site's S3Client (S3_ENDPOINT, inside the
 *   network); presigned URLs are made for its public endpoint (ONLINE_MEDIA_PUBLIC_ENDPOINT), because a browser must
 *   reach them - production's Hetzner endpoint is both, a local MinIO is `minio:9000` inside and the host port outside.
 * - secondary = an optional self-hosted S3-compatible store (Garage / SeaweedFS) taking uploads while the primary is
 *   down (ONLINE_MEDIA_SECONDARY_*; no endpoint = none). One endpoint for both: it is public.
 * - serverLocation(): where a service inside the network reads an object by a presigned URL - the CV worker of the
 *   completeness check (Phase 3, OnlineCvInputs): the primary's own endpoint (S3_ENDPOINT, `minio:9000` locally - the
 *   browser's host port means nothing inside the network), the secondary's one endpoint; serverHosts() = their hosts as
 *   the presigned URLs name them (`bucket.host` for a store addressed by host, no `:443` / `:80`), the only ones a URL
 *   handed to the worker may name (the worker's own allowlist, ONLINE_CV_ALLOWED_HOSTS, is the same).
 * Configuration only - no request is made here.
 */
final readonly class OnlineMediaStores
{
    private OnlineMediaStoreLocation $primaryPublic;
    private OnlineMediaStoreLocation $primaryServer;
    private null|OnlineMediaStoreLocation $secondary;
    private null|S3Client $secondaryClient;

    public function __construct(
        private S3Client $primaryClient,
        string $primaryRegion,
        string $primaryBucket,
        string $primaryAccessKey,
        #[\SensitiveParameter]
        string $primarySecretKey,
        string $primaryPublicEndpoint,
        string $secondaryEndpoint,
        string $secondaryRegion,
        string $secondaryBucket,
        string $secondaryAccessKey,
        #[\SensitiveParameter]
        string $secondarySecretKey,
        bool $secondaryPathStyle,
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        // How the server reaches the primary (S3_ENDPOINT) - empty: the public endpoint (production: one for both)
        string $primaryServerEndpoint = '',
    ) {
        $this->primaryPublic = new OnlineMediaStoreLocation(
            endpoint: $primaryPublicEndpoint,
            region: $primaryRegion,
            bucket: $primaryBucket,
            accessKey: $primaryAccessKey,
            secretKey: $primarySecretKey,
        );
        $this->primaryServer = trim($primaryServerEndpoint) === '' ? $this->primaryPublic : new OnlineMediaStoreLocation(
            endpoint: trim($primaryServerEndpoint),
            region: $primaryRegion,
            bucket: $primaryBucket,
            accessKey: $primaryAccessKey,
            secretKey: $primarySecretKey,
        );

        if (trim($secondaryEndpoint) === '') {
            $this->secondary = null;
            $this->secondaryClient = null;

            return;
        }

        $this->secondary = new OnlineMediaStoreLocation(
            endpoint: trim($secondaryEndpoint),
            region: $secondaryRegion !== '' ? $secondaryRegion : 'us-east-1',
            bucket: $secondaryBucket,
            accessKey: $secondaryAccessKey,
            secretKey: $secondarySecretKey,
            pathStyle: $secondaryPathStyle,
        );

        // Built once, makes no request until used - the same short-timeout HTTP client as the site's own S3Client
        $this->secondaryClient = new S3Client([
            Configuration::OPTION_REGION => $this->secondary->region,
            Configuration::OPTION_ENDPOINT => $this->secondary->endpoint,
            Configuration::OPTION_ACCESS_KEY_ID => $this->secondary->accessKey,
            Configuration::OPTION_SECRET_ACCESS_KEY => $this->secondary->secretKey,
            Configuration::OPTION_PATH_STYLE_ENDPOINT => $this->secondary->pathStyle ? 'true' : 'false',
        ], null, $httpClient, $logger);
    }

    public function hasSecondary(): bool
    {
        return $this->secondary !== null;
    }

    /**
     * Where a browser reaches the store - what presigned URLs are made for
     */
    public function publicLocation(OnlineMediaStore $store): OnlineMediaStoreLocation
    {
        return match ($store) {
            OnlineMediaStore::Primary => $this->primaryPublic,
            OnlineMediaStore::Secondary => $this->secondary ?? throw new LogicException('No second store for online media is configured.'),
        };
    }

    /**
     * Where a service inside the network (the CV worker) reaches the store - what its presigned URLs are made for
     */
    public function serverLocation(OnlineMediaStore $store): OnlineMediaStoreLocation
    {
        return match ($store) {
            OnlineMediaStore::Primary => $this->primaryServer,
            OnlineMediaStore::Secondary => $this->secondary ?? throw new LogicException('No second store for online media is configured.'),
        };
    }

    /**
     * The `host[:port]` of every configured store as its presigned URLs for serverLocation() name it
     * (OnlineMediaStoreLocation::urlHost(): lower case, no port of the scheme's own, `bucket.host` for a store addressed
     * by host) - the only hosts a URL handed to the CV worker may have, and what its ONLINE_CV_ALLOWED_HOSTS must hold
     *
     * @return list<string>
     */
    public function serverHosts(): array
    {
        $hosts = [];

        foreach ([$this->primaryServer, $this->secondary] as $location) {
            $host = $location?->urlHost();
            $host = $host === null ? null : strtolower($host);

            if ($host !== null && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * `host[:port]` of a URL, lower case, the port only when it is not the scheme's own (`https://host:443/…` is
     * `host`, like the presigned URLs name it) - null when it is no URL
     */
    public static function hostOf(string $url): null|string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $port = $parts['port'] ?? null;

        if ($port === null || ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            return strtolower($parts['host']);
        }

        return strtolower($parts['host']) . ':' . $port;
    }

    /**
     * How the server reaches the store (HEAD, DELETE, the bucket's CORS)
     */
    public function client(OnlineMediaStore $store): S3Client
    {
        return match ($store) {
            OnlineMediaStore::Primary => $this->primaryClient,
            OnlineMediaStore::Secondary => $this->secondaryClient ?? throw new LogicException('No second store for online media is configured.'),
        };
    }

    public function bucket(OnlineMediaStore $store): string
    {
        return $this->publicLocation($store)->bucket;
    }
}
