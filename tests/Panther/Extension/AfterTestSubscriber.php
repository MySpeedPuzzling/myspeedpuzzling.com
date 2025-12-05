<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther\Extension;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use SpeedPuzzling\Web\Tests\Panther\AbstractPantherTestCase;

final class AfterTestSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        $test = $event->test();

        if (!$test instanceof TestMethod) {
            return;
        }

        if (!is_subclass_of($test->className(), AbstractPantherTestCase::class)) {
            return;
        }

        PantherDatabaseManager::getInstance()->dropCurrentDatabase();
    }
}
