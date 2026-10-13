<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'mailer' => [
            'transports' => [
                'transactional' => '%env(MAILER_TRANSACTIONAL_DSN)%',
                'notifications' => '%env(MAILER_NOTIFICATIONS_DSN)%',
                // Amazon SES for the addresses in SES_TEST_RECIPIENTS only (MailStreamRouting)
                'ses' => '%env(MAILER_SES_DSN)%',
            ],
            'headers' => [
                'From' => 'MySpeedPuzzling <robot@mail.myspeedpuzzling.com>',
            ],
        ],
    ],
]);
