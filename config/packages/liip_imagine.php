<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'liip_imagine' => [
        'driver' => 'imagick',
        'messenger' => true,
        'twig' => [
            'mode' => 'lazy',
        ],
        'loaders' => [
            'flysystem_loader' => [
                'flysystem' => [
                    'filesystem_service' => 'oneup_flysystem.minio_filesystem',
                ],
            ],
        ],
        'data_loader' => 'flysystem_loader',
        'resolvers' => [
            'flysystem_resolver' => [
                'flysystem' => [
                    'filesystem_service' => 'oneup_flysystem.cached_filesystem',
                    'cache_prefix' => 'thumbnails',
                    'root_url' => '%uploadedAssetsBaseUrl%',
                ],
            ],
        ],
        'cache' => 'flysystem_resolver',
        'filter_sets' => [
            'puzzle_small' => [
                'quality' => 88,
                'filters' => [
                    'auto_rotate' => true,
                    'thumbnail' => [
                        'size' => [200, 200],
                        'mode' => 'inset',
                    ],
                ],
            ],
            'puzzle_small_webp' => [
                'quality' => 82,
                'format' => 'webp',
                'filters' => [
                    'auto_rotate' => true,
                    'thumbnail' => [
                        'size' => [200, 200],
                        'mode' => 'inset',
                    ],
                ],
            ],
            'puzzle_medium' => [
                'quality' => 91,
                'filters' => [
                    'auto_rotate' => true,
                    'thumbnail' => [
                        'size' => [400, 400],
                        'mode' => 'inset',
                        'allow_upscale' => false,
                    ],
                ],
            ],
            'puzzle_medium_webp' => [
                'quality' => 85,
                'format' => 'webp',
                'filters' => [
                    'auto_rotate' => true,
                    'thumbnail' => [
                        'size' => [400, 400],
                        'mode' => 'inset',
                        'allow_upscale' => false,
                    ],
                ],
            ],
        ],
    ],
]);
