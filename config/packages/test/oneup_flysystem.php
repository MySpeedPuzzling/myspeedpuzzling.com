<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use League\Flysystem\Filesystem;

return App::config([
    'oneup_flysystem' => [
        'adapters' => [
            'minio' => [
                'memory' => null,
            ],
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
