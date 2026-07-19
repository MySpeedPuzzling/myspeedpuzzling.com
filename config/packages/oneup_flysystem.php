<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use AsyncAws\S3\S3Client;
use League\Flysystem\Filesystem;

return App::config([
    'oneup_flysystem' => [
        'adapters' => [
            'minio' => [
                'async_aws_s3' => [
                    'client' => S3Client::class,
                    'bucket' => '%env(S3_BUCKET)%',
                ],
            ],
        ],
        'filesystems' => [
            'minio' => [
                'adapter' => 'minio',
                'alias' => Filesystem::class,
                // No 'visibility' => 'public' here: it would send a public-read ACL
                // per object, which Hetzner Object Storage honors — punching public
                // holes in the private bucket. Reads go through credentialed imgproxy.
            ],
        ],
    ],
]);
