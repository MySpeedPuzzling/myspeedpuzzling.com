<?php

declare(strict_types=1);

use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mercure\HubInterface;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->defaults()
        ->autoconfigure()
        ->autowire()
        ->public();

    // Data fixtures
    $services->load('SpeedPuzzling\\Web\\Tests\\DataFixtures\\', __DIR__ . '/../tests/DataFixtures/{*.php}');

    // Mercure test double
    $services->set(NullMercureHub::class);
    $services->alias(HubInterface::class, NullMercureHub::class);
};
