<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use SpeedPuzzling\Web\Message\PrepareDigestEmailForPlayer;
use SpeedPuzzling\Web\Message\SendPlayerContentDigest;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;

return App::config([
    'framework' => [
        'messenger' => [
            'routing' => [
                SendEmailMessage::class => 'sync',
                PrepareDigestEmailForPlayer::class => 'sync',
                SendPlayerContentDigest::class => 'sync',
                'SpeedPuzzling\\Web\\Events\\*' => 'sync',
            ],
        ],
    ],
]);
