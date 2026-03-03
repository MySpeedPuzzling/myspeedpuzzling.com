<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\GenerateTwitchLink;

class GenerateTwitchLinkTest extends TestCase
{
    #[DataProvider('provideFromUserInputData')]
    public function testFromUserInput(string $input, null|string $expectedLink, string $expectedText): void
    {
        $linkGenerator = new GenerateTwitchLink();
        $link = $linkGenerator->fromUserInput($input);

        self::assertSame($expectedLink, $link->link);
        self::assertSame($expectedText, $link->text);
    }

    /**
     * @return Generator<array{string, null|string, string}>
     */
    public static function provideFromUserInputData(): Generator
    {
        yield ['myspeedpuzzling', 'https://www.twitch.tv/myspeedpuzzling', 'myspeedpuzzling'];
        yield ['https://www.twitch.tv/myspeedpuzzling', 'https://www.twitch.tv/myspeedpuzzling', 'myspeedpuzzling'];
        yield ['https://twitch.tv/myspeedpuzzling', 'https://twitch.tv/myspeedpuzzling', 'myspeedpuzzling'];
    }
}
