<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\PHPStan\Fixture;

use DateTimeImmutable;

/**
 * A subclass keeping DateTimeImmutable's constructor - SystemClockRuleTest's fixture
 */
final class LaterMoment extends DateTimeImmutable
{
}
