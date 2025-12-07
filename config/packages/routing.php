<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'router' => [
            'utf8' => true,
            'default_uri' => '%env(APP_URL)%',
        ],
    ],
]);
