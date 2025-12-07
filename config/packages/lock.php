<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'lock' => '%env(LOCK_DSN)%',
    ],
]);
