<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'validation' => [
            'email_validation_mode' => 'html5',
        ],
    ],
]);
