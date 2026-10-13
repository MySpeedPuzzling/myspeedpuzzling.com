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
 * Configuration only - no request is made here.
 */
final readonly class OnlineMediaStores
{
    private OnlineMediaStoreLocation $primaryPublic;
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
    ) {
        $this->primaryPublic = new OnlineMediaStoreLocation(
            endpoint: $primaryPublicEndpoint,
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
