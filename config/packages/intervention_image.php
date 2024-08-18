<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('intervention_image', [
        'driver' => Intervention\Image\Drivers\Imagick\Driver::class,
        'options' => [
            'autoOrientation' => true,
            'decodeAnimation' => false,
            'blendingColor' => 'ffffff',
        ]
    ]);
};
