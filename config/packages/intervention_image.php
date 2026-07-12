<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Intervention\Image\Drivers\Imagick\Driver;

return App::config([
    'intervention_image' => [
        'driver' => Driver::class,
        'options' => [
            'autoOrientation' => true,
            // decodeAnimation must stay true: intervention/image 4.2.0 Imagick driver
            // fails to decode ANY image with false ("Can not process empty Imagick object"
            // in RemoveAnimationModifier). All our usages decode static images anyway.
            'decodeAnimation' => true,
            'backgroundColor' => 'ffffff',
        ],
    ],
]);
