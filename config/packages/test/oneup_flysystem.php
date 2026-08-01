<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use League\Flysystem\Filesystem;

// The `minio` adapter is NOT overridden here: tests exercise the real
// FailoverS3Adapter. Its inner S3 adapter and spool adapter are swapped for
// in-memory doubles in config/services_test.php instead.
return App::config([
    'oneup_flysystem' => [
        'adapters' => [
            'cached' => [
                'memory' => null,
            ],
        ],
        'filesystems' => [
            'minio' => [
                'adapter' => 'minio',
                'alias' => Filesystem::class,
                'visibility' => 'public',
                'directory_visibility' => 'public',
            ],
            'cached' => [
                'adapter' => 'cached',
            ],
        ],
    ],
]);
