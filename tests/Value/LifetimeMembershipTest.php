<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\LifetimeMembership;

final class LifetimeMembershipTest extends TestCase
{
    public function testGrantedUntilIsRecognisedAsLifetime(): void
    {
        self::assertTrue(LifetimeMembership::isLifetime(LifetimeMembership::grantedUntil()));
    }

    public function testLifetimeSurvivesTimezoneShift(): void
    {
        $shifted = LifetimeMembership::grantedUntil()->setTimezone(new DateTimeZone('Pacific/Kiritimati'));

        self::assertTrue(LifetimeMembership::isLifetime($shifted));
    }

    public function testRegularGrantIsNotLifetime(): void
    {
        self::assertFalse(LifetimeMembership::isLifetime(null));
        self::assertFalse(LifetimeMembership::isLifetime(new DateTimeImmutable('+10 years')));
    }
}
