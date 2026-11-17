<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\PlayerReference;

final class PlayerReferenceTest extends TestCase
{
    private const string ID = '018d0000-0000-0000-0000-000000000001';

    /**
     * @return iterable<string, array{string, null|string}>
     */
    public static function provideCells(): iterable
    {
        yield 'uuid' => [self::ID, 'id:' . self::ID];
        yield 'uuid upper-cased with spaces' => ['  ' . strtoupper(self::ID) . ' ', 'id:' . self::ID];
        yield '#code' => ['#AbC123', 'code:abc123'];
        yield 'bare code' => ['abc', 'code:abc'];
        yield 'longest code' => ['#abcdefgh', 'code:abcdefgh'];
        yield 'profile link' => ['https://myspeedpuzzling.com/en/player-profile/' . self::ID, 'id:' . self::ID];
        yield 'czech profile link with query and fragment' => ['https://myspeedpuzzling.com/profil-hrace/' . self::ID . '?a=1#top', 'id:' . self::ID];
        yield 'japanese profile link, encoded' => ['https://myspeedpuzzling.com/ja/%E3%83%97%E3%83%AC%E3%82%A4%E3%83%A4%E3%83%BC-%E3%83%97%E3%83%AD%E3%83%95%E3%82%A3%E3%83%BC%E3%83%AB/' . self::ID . '/', 'id:' . self::ID];
        yield 'no scheme' => ['myspeedpuzzling.com/en/player-profile/' . self::ID, 'id:' . self::ID];
        yield 'empty' => ['  ', null];
        yield 'too short' => ['#ab', null];
        yield 'too long' => ['#abcdefghi', null];
        yield 'other characters' => ['a_b-c', null];
        yield 'link ending in a code' => ['https://myspeedpuzzling.com/en/player-profile/abc123', null];
        yield 'uuid without dashes' => [str_replace('-', '', self::ID), null];
    }

    #[DataProvider('provideCells')]
    public function testParse(string $cell, null|string $key): void
    {
        self::assertSame($key, PlayerReference::parse($cell)?->key());
    }
}
