<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'doctrine_migrations' => [
        'migrations_paths' => [
            'SpeedPuzzling\\Web\\Migrations' => '%kernel.project_dir%/migrations',
        ],
        'all_or_nothing' => true,
        'enable_profiler' => '%kernel.debug%',
    ],
]);
