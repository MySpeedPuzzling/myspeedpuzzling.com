<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'services' => [
        '_defaults' => [
            'autoconfigure' => true,
            'autowire' => true,
        ],
        // Test controllers - only available in dev environment
        'SpeedPuzzling\\Web\\Controller\\Test\\' => [
            'resource' => '../../../src/Controller/Test/{*Controller.php}',
        ],
    ],
]);
