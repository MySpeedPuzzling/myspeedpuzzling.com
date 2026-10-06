<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\ApiCaller;
use SpeedPuzzling\Web\Value\ApiCallerKind;
use SpeedPuzzling\Web\Value\ApiStatusClass;

final class ApiCallerTest extends TestCase
{
    private const string TOKEN = '018d0000-0000-0000-0000-0000000000a1';
    private const string PLAYER = '018d0000-0000-0000-0000-000000000001';

    public function testKeysTurnBackIntoTheirCallers(): void
    {
        foreach (
            [
            ApiCaller::personalAccessToken(self::TOKEN, self::PLAYER),
            ApiCaller::oauth2User('puzzle-rush-2-0-8ea6', self::PLAYER),
            ApiCaller::oauth2Client('weird:client|id'),
            ] as $caller
        ) {
            self::assertEquals($caller, ApiCaller::fromKey($caller->key()));
        }

        self::assertSame('pat:' . self::TOKEN . ':' . self::PLAYER, ApiCaller::personalAccessToken(self::TOKEN, self::PLAYER)->key());
        self::assertSame('client:weird%3Aclient%7Cid', ApiCaller::oauth2Client('weird:client|id')->key());
        self::assertSame(ApiCallerKind::OAuth2User, ApiCaller::fromKey('oauth:app:' . self::PLAYER)->kind);
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsAnythingElse(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApiCaller::fromKey($key);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'unknown kind' => ['user:' . self::PLAYER];
        yield 'pat without player' => ['pat:' . self::TOKEN];
        yield 'pat with a non-uuid' => ['pat:abc:' . self::PLAYER];
        yield 'client without id' => ['client:'];
        yield 'oauth with a non-uuid player' => ['oauth:app:nobody'];
    }

    public function testStatusClasses(): void
    {
        self::assertSame(ApiStatusClass::Success, ApiStatusClass::fromStatusCode(201));
        self::assertSame(ApiStatusClass::Redirect, ApiStatusClass::fromStatusCode(304));
        self::assertSame(ApiStatusClass::ClientError, ApiStatusClass::fromStatusCode(422));
        self::assertSame(ApiStatusClass::TooManyRequests, ApiStatusClass::fromStatusCode(429));
        self::assertSame(ApiStatusClass::ServerError, ApiStatusClass::fromStatusCode(503));
        self::assertTrue(ApiStatusClass::TooManyRequests->isFailure());
        self::assertFalse(ApiStatusClass::Redirect->isFailure());
    }
}
