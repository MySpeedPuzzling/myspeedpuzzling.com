<?php declare(strict_types=1);


use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    # Documentation on how to configure the bundle can be found at: https://symfony.com/doc/current/bundles/LiipImagineBundle/basic-usage.html

    $containerConfigurator->extension('liip_imagine', [
        'driver' => 'gd',
        'loaders' => [
            'flysystem_loader' => [
                'flysystem' => [
                    'filesystem_service' => 'oneup_flysystem.minio_filesystem',
                ],
            ],
        ],
        'resolvers' => [
            'flysystem_resolver' => [
                'flysystem' => [
                    'filesystem_service' => 'oneup_flysystem.minio_filesystem',
                    'cache_prefix' => 'thumbnails',
                    'root_url' => 'https://img.speedpuzzling.cz/puzzle/',
                ],
            ],
        ],
        'cache' => 'flysystem_resolver',
        'data_loader' => 'flysystem_loader',
        'filter_sets' => [
            'puzzle_small' => [
                'filters' => [
                    'thumbnail' => [
                        'size' => [200, 200],
                        'mode' => 'outbound',
                        'allow_upscale' => false,
                    ]
                ],
            ],
        ],
    ]);
};
