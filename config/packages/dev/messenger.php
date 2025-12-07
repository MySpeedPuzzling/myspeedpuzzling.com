<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Mailer\Messenger\SendEmailMessage;

return App::config([
    'framework' => [
        'messenger' => [
            'routing' => [
                SendEmailMessage::class => 'sync',
                'SpeedPuzzling\\Web\\Events\\*' => 'sync',
            ],
        ],
    ],
]);
