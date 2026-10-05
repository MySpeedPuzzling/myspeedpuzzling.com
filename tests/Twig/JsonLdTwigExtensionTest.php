<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Twig\JsonLdTwigExtension;

final class JsonLdTwigExtensionTest extends TestCase
{
    public function testNothingTheHtmlParserReactsTo(): void
    {
        $value = ['name' => 'Kočka & myš <!--<script></script> "1" it\'s', 'pieces' => 500, 'price' => 22.5];

        $json = JsonLdTwigExtension::encode($value);

        foreach (['<', '>', '&', "'"] as $character) {
            self::assertStringNotContainsString($character, $json);
        }

        self::assertSame($value, json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testOrdinaryDataComesOutAsBefore(): void
    {
        foreach (['Ravensburger', 'https://myspeedpuzzling.com/en/puzzle/1', 'Mušle', 500, ['a', 'b']] as $value) {
            self::assertSame(json_encode($value), JsonLdTwigExtension::encode($value));
        }
    }
}
