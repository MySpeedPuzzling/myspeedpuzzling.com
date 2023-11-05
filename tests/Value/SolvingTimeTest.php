<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use SpeedPuzzling\Web\Value\SolvingTime;
use PHPUnit\Framework\TestCase;

class SolvingTimeTest extends TestCase
{
    public function testFromInput(): void
    {
        $time = SolvingTime::fromUserInput('1:01:06');

        self::assertSame(3666, $time->seconds);
    }
}
