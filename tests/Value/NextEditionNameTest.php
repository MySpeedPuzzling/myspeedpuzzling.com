<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\NextEditionName;

final class NextEditionNameTest extends TestCase
{
    #[DataProvider('names')]
    public function testTheNumberAtTheEndGoesUp(string $name, string $next): void
    {
        self::assertSame($next, NextEditionName::of($name));
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function names(): iterable
    {
        yield ['Cup #4', 'Cup #5'];
        yield ['Session 9', 'Session 10'];
        yield ['Week 09', 'Week 10'];
        yield ['Puzzle Night 2026', 'Puzzle Night 2027'];
        yield ['EJJ #69 — May 2026', 'EJJ #69 — May 2027'];
        yield ['Summer Cup', 'Summer Cup'];
        yield ['42', '43'];
    }
}
