<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Sentry;

use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use SpeedPuzzling\Web\Services\Sentry\SentryScopeResetter;

final class SentryScopeResetterTest extends TestCase
{
    public function testResetClearsScope(): void
    {
        $hub = new Hub();
        $hub->configureScope(static function (Scope $scope): void {
            $scope->addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'app'));
            $scope->setUser(UserDataBag::createFromUserIdentifier('user-1'));
            $scope->setTag('route', 'my_route');
        });

        $resetter = new SentryScopeResetter($hub);
        $resetter->reset();

        $event = null;
        $hub->configureScope(static function (Scope $scope) use (&$event): void {
            $event = $scope->applyToEvent(Event::createEvent());
        });

        self::assertNotNull($event);
        self::assertSame([], $event->getBreadcrumbs());
        self::assertNull($event->getUser());
        self::assertSame([], $event->getTags());
    }
}
