<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\OnlineToken;

/**
 * Personal link tokens (docs/features/online-competitions/README.md §5.4): 128 random bits in base58, 22 characters.
 */
final class OnlineTokenTest extends TestCase
{
    private const string ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public function testGeneratedTokensAreTwentyTwoBase58CharactersAndNeverRepeat(): void
    {
        $tokens = [];

        for ($i = 0; $i < 500; $i++) {
            $token = OnlineToken::generate();

            self::assertSame(OnlineToken::LENGTH, strlen($token));
            self::assertTrue(OnlineToken::looksValid($token), $token);
            $tokens[$token] = true;
        }

        self::assertCount(500, $tokens);
    }

    public function testTheSmallestNumbersArePaddedWithTheZeroDigit(): void
    {
        self::assertSame(str_repeat('1', 22), OnlineToken::fromBytes(str_repeat("\x00", 16)));
        self::assertSame(str_repeat('1', 21) . '2', OnlineToken::fromBytes(str_repeat("\x00", 15) . "\x01"));
        self::assertSame(str_repeat('1', 21) . 'z', OnlineToken::fromBytes(str_repeat("\x00", 15) . "\x39"));
        self::assertSame(str_repeat('1', 20) . '21', OnlineToken::fromBytes(str_repeat("\x00", 15) . "\x3a"));
    }

    public function testTheLargestNumberStillFitsTwentyTwoCharacters(): void
    {
        $token = OnlineToken::fromBytes(str_repeat("\xff", 16));

        self::assertSame(22, strlen($token));
        self::assertSame(self::decimalOfBytes(str_repeat("\xff", 16)), self::decimalOfToken($token));
    }

    public function testEveryTokenIsItsBytesInBase58(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $bytes = random_bytes(16);

            self::assertSame(self::decimalOfBytes($bytes), self::decimalOfToken(OnlineToken::fromBytes($bytes)), bin2hex($bytes));
        }
    }

    public function testOnlySixteenBytesMakeAToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OnlineToken::fromBytes(random_bytes(15));
    }

    #[DataProvider('provideShapes')]
    public function testTheShapeIsCheckedBeforeALookup(string $token, bool $looksValid): void
    {
        self::assertSame($looksValid, OnlineToken::looksValid($token));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideShapes(): iterable
    {
        yield 'a token' => ['2ConnectedTestCupToken', true];
        yield 'all ones' => [str_repeat('1', 22), true];
        yield 'too short' => [str_repeat('A', 21), false];
        yield 'too long' => [str_repeat('A', 23), false];
        yield 'empty' => ['', false];
        yield 'a zero' => ['0' . str_repeat('A', 21), false];
        yield 'a capital O' => ['O' . str_repeat('A', 21), false];
        yield 'a capital I' => ['I' . str_repeat('A', 21), false];
        yield 'a small l' => ['l' . str_repeat('A', 21), false];
        yield 'a trailing new line' => [str_repeat('A', 22) . "\n", false];
        yield 'a path' => ['../' . str_repeat('A', 19), false];
        yield 'a uuid' => ['018d0050-0000-0000-0000-000000000201', false];
    }

    private static function decimalOfBytes(string $bytes): string
    {
        $decimal = '0';

        foreach (str_split($bytes) as $byte) {
            $decimal = bcadd(bcmul($decimal, '256'), (string) ord($byte));
        }

        return $decimal;
    }

    private static function decimalOfToken(string $token): string
    {
        $decimal = '0';

        foreach (str_split($token) as $character) {
            $digit = strpos(self::ALPHABET, $character);
            self::assertIsInt($digit, $character);
            $decimal = bcadd(bcmul($decimal, '58'), (string) $digit);
        }

        return $decimal;
    }
}
