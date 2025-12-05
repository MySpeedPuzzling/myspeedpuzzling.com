<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther\Extension;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use SpeedPuzzling\Web\Tests\Panther\AbstractPantherTestCase;

final class BeforeTestSubscriber implements PreparationStartedSubscriber
{
    public function notify(PreparationStarted $event): void
    {
        $test = $event->test();

        if (!$test instanceof TestMethod) {
            return;
        }

        if (!is_subclass_of($test->className(), AbstractPantherTestCase::class)) {
            return;
        }

        PantherDatabaseManager::getInstance()->createDatabaseForTest($test->id());
    }
}
