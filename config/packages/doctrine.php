<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Ramsey\Uuid\Doctrine\UuidType;
use SpeedPuzzling\Web\Services\Doctrine\CustomIndexFilteringSchemaManagerFactory;
use SpeedPuzzling\Web\Doctrine\LapsArrayDoctrineType;
use SpeedPuzzling\Web\Doctrine\PuzzlersGroupDoctrineType;
use SpeedPuzzling\Web\Doctrine\SellSwapListSettingsDoctrineType;

return App::config([
    'doctrine' => [
        'dbal' => [
            'url' => '%env(resolve:DATABASE_URL)%',
            'types' => [
                'uuid' => UuidType::class,
                LapsArrayDoctrineType::NAME => LapsArrayDoctrineType::class,
                PuzzlersGroupDoctrineType::NAME => PuzzlersGroupDoctrineType::class,
                SellSwapListSettingsDoctrineType::NAME => SellSwapListSettingsDoctrineType::class,
            ],
            'schema_filter' => '~^(?!tmp_|custom_)~',
            'schema_manager_factory' => CustomIndexFilteringSchemaManagerFactory::class,
        ],
        'orm' => [
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
    ],
]);
