<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'web_profiler' => [
        'toolbar' => false,
        'intercept_redirects' => false,
    ],
    'framework' => [
        'profiler' => [
            'collect' => false,
        ],
    ],
]);
