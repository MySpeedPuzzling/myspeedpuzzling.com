<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther\Extension;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

final class PantherDatabaseExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new BeforeTestSubscriber());
        $facade->registerSubscriber(new AfterTestSubscriber());
    }
}
