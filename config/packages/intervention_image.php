<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Intervention\Image\Drivers\Imagick\Driver;

return App::config([
    'intervention_image' => [
        'driver' => Driver::class,
        'options' => [
            'autoOrientation' => true,
            'decodeAnimation' => false,
            'blendingColor' => 'ffffff',
        ],
    ],
]);
