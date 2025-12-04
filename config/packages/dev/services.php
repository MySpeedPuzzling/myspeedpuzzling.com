<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->defaults()
        ->autoconfigure()
        ->autowire();

    // Test controllers - only available in dev environment
    $services->load('SpeedPuzzling\\Web\\Controller\\Test\\', __DIR__ . '/../../../src/Controller/Test/{*Controller.php}');
};
