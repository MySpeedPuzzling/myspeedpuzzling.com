<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'web_profiler' => [
        'toolbar' => true,
        'intercept_redirects' => false,
    ],
    'framework' => [
        'profiler' => [
            'enabled' => true,
            'collect' => false, // Disabled by default, enabled via DebugToolbarListener
        ],
    ],
]);
