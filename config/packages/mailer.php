<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'mailer' => [
            'transports' => [
                'transactional' => '%env(MAILER_TRANSACTIONAL_DSN)%',
                'notifications' => '%env(MAILER_NOTIFICATIONS_DSN)%',
            ],
            'envelope' => [
                'sender' => 'robot@mail.myspeedpuzzling.com',
            ],
            'headers' => [
                'From' => 'MySpeedPuzzling <robot@mail.myspeedpuzzling.com>',
            ],
        ],
    ],
]);
