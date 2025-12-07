<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'mailer' => [
            'dsn' => '%env(MAILER_DSN)%',
            'envelope' => [
                'sender' => 'robot@speedpuzzling.cz',
            ],
            'headers' => [
                'From' => 'MySpeedPuzzling <robot@speedpuzzling.cz>',
            ],
        ],
    ],
]);
