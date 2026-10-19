<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'mailer' => [
            'transports' => [
                'transactional' => '%env(MAILER_TRANSACTIONAL_DSN)%',
                'notifications' => '%env(MAILER_NOTIFICATIONS_DSN)%',
                // Amazon SES, tried first for every stream when configured - MailStreamRouting decides
                'ses' => '%env(MAILER_SES_DSN)%',
            ],
            'headers' => [
                'From' => 'MySpeedPuzzling <robot@mail.myspeedpuzzling.com>',
            ],
        ],
    ],
]);
