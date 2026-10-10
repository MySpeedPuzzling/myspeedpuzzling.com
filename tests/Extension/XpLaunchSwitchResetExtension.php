<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Extension;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness;

/**
 * Every test starts launched (the XP launch switch on, FixedXpLaunchReadiness) - a test of the time before the launch
 * turns it off with `FixedXpLaunchReadiness::$ready = false` and never has to put it back.
 */
final class XpLaunchSwitchResetExtension implements Extension, PreparationStartedSubscriber
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber($this);
    }

    public function notify(PreparationStarted $event): void
    {
        FixedXpLaunchReadiness::$ready = true;
        FixedXpLaunchReadiness::$readyAt = null;
    }
}
