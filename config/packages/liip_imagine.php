<?php declare(strict_types=1);


use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    # Documentation on how to configure the bundle can be found at: https://symfony.com/doc/current/bundles/LiipImagineBundle/basic-usage.html

    $containerConfigurator->extension('liip_imagine', [
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
                    ]
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
                    ]
                ],
            ],
        ],
    ]);
};
