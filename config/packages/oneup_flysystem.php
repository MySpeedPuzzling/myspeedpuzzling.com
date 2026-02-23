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
                    'bucket' => 'puzzle',
                ],
            ],
        ],
        'filesystems' => [
            'minio' => [
                'adapter' => 'minio',
                'alias' => Filesystem::class,
                'visibility' => 'public',
                'directory_visibility' => 'public',
            ],
        ],
    ],
]);
