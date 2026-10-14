<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where an S3-compatible store of online competition evidence answers, and with what keys (OnlineMediaStores): the
 * endpoint (`https://host[:port][/base]`), its region, the bucket, the access key and whether objects are addressed by
 * path (`endpoint/bucket/key` - MinIO, Hetzner, Garage) or by host (`bucket.host/key`).
 */
final readonly class OnlineMediaStoreLocation
{
    public function __construct(
        public string $endpoint,
        public string $region,
        public string $bucket,
        public string $accessKey,
        #[\SensitiveParameter]
        public string $secretKey,
        public bool $pathStyle = true,
    ) {
    }

    /**
     * The host a presigned URL for this store names - its Host header (S3QueryPresigner): the endpoint's host in lower
     * case, the port only when it is not the scheme's own (`https://host:443` is `host`), the bucket in front for a
     * store addressed by host (`bucket.host`); null when the endpoint is no URL
     */
    public function urlHost(): null|string
    {
        $endpoint = parse_url($this->endpoint);

        if ($endpoint === false || !isset($endpoint['scheme'], $endpoint['host']) || $endpoint['host'] === '') {
            return null;
        }

        $scheme = strtolower($endpoint['scheme']);
        $host = strtolower($endpoint['host']);
        $port = $endpoint['port'] ?? null;

        if ($port !== null && !($scheme === 'https' && $port === 443) && !($scheme === 'http' && $port === 80)) {
            $host .= ':' . $port;
        }

        return $this->pathStyle ? $host : $this->bucket . '.' . $host;
    }
}
