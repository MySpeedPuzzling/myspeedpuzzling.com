<?php

declare(strict_types=1);

use Ramsey\Uuid\Doctrine\UuidType;
use SpeedPuzzling\Web\Doctrine\LapsArrayDoctrineType;
use SpeedPuzzling\Web\Doctrine\PuzzlersGroupDoctrineType;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('doctrine', [
        'dbal' => [
            'url' => '%env(resolve:DATABASE_URL)%',
            'types' => [
                'uuid' => UuidType::class,
                LapsArrayDoctrineType::NAME => LapsArrayDoctrineType::class,
                PuzzlersGroupDoctrineType::NAME => PuzzlersGroupDoctrineType::class,
            ],
            'schema_filter' => '~^(?!tmp_)~',
        ],
        'orm' => [
            'report_fields_where_declared' => true,
            'auto_generate_proxy_classes' => true,
            'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
            'auto_mapping' => true,
            'mappings' => [
                'SpeedPuzzling' => [
                    'type' => 'attribute',
                    'dir' => '%kernel.project_dir%/src/Entity',
                    'prefix' => 'SpeedPuzzling\\Web\\Entity',
                ],
            ],
        ],
    ]);
};
