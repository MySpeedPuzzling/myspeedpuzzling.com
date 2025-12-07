<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'twig_component' => [
        'anonymous_template_directory' => 'components/',
        'defaults' => [
            'SpeedPuzzling\\Web\\Component\\' => 'components/',
        ],
    ],
]);
