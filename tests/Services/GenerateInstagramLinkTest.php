<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\GenerateInstagramLink;

class GenerateInstagramLinkTest extends TestCase
{
    #[DataProvider('provideFromUserInputData')]
    public function testFromUserInput(string $input, null|string $expectedLink, string $expectedText): void
    {
        $linkGenerator = new GenerateInstagramLink();
        $link = $linkGenerator->fromUserInput($input);

        self::assertSame($expectedLink, $link->link);
        self::assertSame($expectedText, $link->text);
    }

    /**
     * @return Generator<array{string, null|string, string}>
     */
    public static function provideFromUserInputData(): Generator
    {
        yield ['@myspeedpuzzling', 'https://www.instagram.com/myspeedpuzzling/', '@myspeedpuzzling'];
        yield ['myspeedpuzzling', 'https://www.instagram.com/myspeedpuzzling/', '@myspeedpuzzling'];
        yield ['https://www.instagram.com/myspeedpuzzling/', 'https://www.instagram.com/myspeedpuzzling/', '@myspeedpuzzling'];
        yield ['https://www.instagram.com/myspeedpuzzling', 'https://www.instagram.com/myspeedpuzzling', '@myspeedpuzzling'];
        yield ['https://www.instagram.com/myspeedpuzzling?igsh=xyz&utm_source=qr', 'https://www.instagram.com/myspeedpuzzling?igsh=xyz&utm_source=qr', '@myspeedpuzzling'];
        yield ['https://instagram.com/myspeedpuzzling/', 'https://instagram.com/myspeedpuzzling/', '@myspeedpuzzling'];
        yield ['https://instagram.com/myspeedpuzzling', 'https://instagram.com/myspeedpuzzling', '@myspeedpuzzling'];
        yield ['https://instagram.com/myspeedpuzzling?igsh=xyz&utm_source=qr', 'https://instagram.com/myspeedpuzzling?igsh=xyz&utm_source=qr', '@myspeedpuzzling'];
    }
}
