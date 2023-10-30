<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('doctrine_migrations', [
        'migrations_paths' => [
            'SpeedPuzzling\\Web\\Migrations' => '%kernel.project_dir%/migrations'
        ],
        'all_or_nothing' => true,
        'enable_profiler' => '%kernel.debug%'
    ]);
};
