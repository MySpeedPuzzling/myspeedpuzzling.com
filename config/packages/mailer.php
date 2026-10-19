<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'mailer' => [
            // One transport for every stream (Amazon SES on production) - MailStreamRouting marks the stream,
            // retries once and pauses, Messenger retries the rest (docs/features/mass-mailing/README.md "Reliability")
            'dsn' => '%env(MAILER_DSN)%',
            'headers' => [
                'From' => 'MySpeedPuzzling <robot@mail.myspeedpuzzling.com>',
            ],
        ],
    ],
]);
