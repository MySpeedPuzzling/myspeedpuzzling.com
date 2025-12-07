<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'nelmio_cors' => [
        'defaults' => [
            'allow_origin' => ['*'],
            'allow_headers' => ['*'],
            'allow_methods' => ['GET', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE'],
            'skip_same_as_origin' => true,
            'max_age' => 3600,
        ],
    ],
]);
