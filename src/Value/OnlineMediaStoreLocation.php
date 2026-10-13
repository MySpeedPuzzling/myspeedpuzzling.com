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
}
