<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'webpack_encore' => [
        'output_path' => '%kernel.project_dir%/public/build',
        'script_attributes' => [
            'defer' => true,
            'data-turbo-track' => 'reload',
        ],
        'link_attributes' => [
            'data-turbo-track' => 'reload',
        ],
    ],
]);
