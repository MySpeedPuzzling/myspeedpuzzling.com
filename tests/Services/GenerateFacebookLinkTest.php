<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\GenerateFacebookLink;

class GenerateFacebookLinkTest extends TestCase
{
    #[DataProvider('provideFromUserInputData')]
    public function testFromUserInput(string $input, null|string $expectedLink, string $expectedText): void
    {
        $linkGenerator = new GenerateFacebookLink();
        $link = $linkGenerator->fromUserInput($input);

        self::assertSame($expectedLink, $link->link);
        self::assertSame($expectedText, $link->text);
    }

    /**
     * @return Generator<array{string, null|string, string}>
     */
    public static function provideFromUserInputData(): Generator
    {
        yield ['myspeedpuzzling', null, 'myspeedpuzzling'];
        yield ['https://www.facebook.com/myspeedpuzzling/', 'https://www.facebook.com/myspeedpuzzling/', 'myspeedpuzzling'];
        yield ['https://www.facebook.com/myspeedpuzzling', 'https://www.facebook.com/myspeedpuzzling', 'myspeedpuzzling'];
        yield ['https://facebook.com/myspeedpuzzling/', 'https://facebook.com/myspeedpuzzling/', 'myspeedpuzzling'];
        yield ['https://facebook.com/myspeedpuzzling', 'https://facebook.com/myspeedpuzzling', 'myspeedpuzzling'];
    }
}
