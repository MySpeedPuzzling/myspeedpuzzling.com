<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Extension;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use SpeedPuzzling\Web\Tests\TestDouble\AnalyticsStudioWatchPool;

/**
 * Every test starts with no analytics studio watched (AnalyticsStudioWatchPool) - no studio signal leaks from one test's
 * state request into another test's results write.
 */
final class AnalyticsStudioWatchResetExtension implements Extension, PreparationStartedSubscriber
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber($this);
    }

    public function notify(PreparationStarted $event): void
    {
        AnalyticsStudioWatchPool::forget();
    }
}
