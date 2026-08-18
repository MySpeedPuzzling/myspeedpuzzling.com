<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use League\Flysystem\Filesystem;
use SpeedPuzzling\Web\Services\Storage\FailoverS3Adapter;

return App::config([
    'oneup_flysystem' => [
        'adapters' => [
            'minio' => [
                // FailoverS3Adapter wraps the raw AsyncAwsS3Adapter
                // (app.storage.s3_adapter, config/services.php) with a local
                // spool so an object-storage outage never breaks user flows.
                'custom' => [
                    'service' => FailoverS3Adapter::class,
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
