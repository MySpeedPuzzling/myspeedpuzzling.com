<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SocialLogin;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Services\SocialLogin\SocialProfileFetcher;
use SpeedPuzzling\Web\Tests\TestDouble\AppleIdTokenFactory;
use SpeedPuzzling\Web\Tests\TestDouble\SocialLoginHttpMock;
use SpeedPuzzling\Web\Value\OauthProvider;
use SpeedPuzzling\Web\Value\SocialUserProfile;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Apple's id_token claims, read by our own rules (the library treats the
 * string "false" as truthy and checks neither audience nor issuer).
 */
final class SocialProfileFetcherAppleTest extends KernelTestCase
{
    private const array ENV = [
        'APPLE_CLIENT_ID' => AppleIdTokenFactory::CLIENT_ID,
        'APPLE_TEAM_ID' => 'TESTTEAM01',
        'APPLE_KEY_ID' => 'TESTKEY001',
    ];

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        SocialLoginHttpMock::reset();

        foreach (self::ENV + ['APPLE_PRIVATE_KEY' => AppleIdTokenFactory::clientSecretKeyPem()] as $name => $value) {
            $original = $_ENV[$name] ?? false;
            $this->originalEnv[$name] = is_string($original) ? $original : false;
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        self::bootKernel();
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $original) {
            if ($original === false) {
                unset($_ENV[$name], $_SERVER[$name]);

                continue;
            }

            $_ENV[$name] = $original;
            $_SERVER[$name] = $original;
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function emailVerifiedClaims(): iterable
    {
        yield 'boolean true' => [true, true];
        yield 'string true' => ['true', true];
        yield 'boolean false' => [false, false];
        yield 'string false' => ['false', false];
        yield 'missing' => [null, false];
    }

    #[DataProvider('emailVerifiedClaims')]
    public function testEmailVerifiedClaim(mixed $claim, bool $expected): void
    {
        $claims = ['sub' => 'apple-sub-1', 'email' => 'someone@example.com'];

        if ($claim !== null) {
            $claims['email_verified'] = $claim;
        }

        AppleIdTokenFactory::queueTokenExchange($claims);

        $profile = $this->fetch();

        self::assertSame($expected, $profile->emailVerified);
        // An explicitly unverified email is kept - rule 4 registers it unverified
        self::assertSame('someone@example.com', $profile->email);
        self::assertSame('apple-sub-1', $profile->providerUserId);
    }

    public function testPrivateRelayIsExposed(): void
    {
        AppleIdTokenFactory::queueTokenExchange([
            'sub' => 'apple-sub-2',
            'email' => 'abc123@privaterelay.appleid.com',
            'email_verified' => 'true',
            'is_private_email' => 'true',
        ]);

        self::assertTrue($this->fetch()->isPrivateRelay);
    }

    public function testRegularAddressIsNotAPrivateRelay(): void
    {
        AppleIdTokenFactory::queueTokenExchange([
            'sub' => 'apple-sub-3',
            'email' => 'real@example.com',
            'email_verified' => true,
            'is_private_email' => 'false',
        ]);

        self::assertFalse($this->fetch()->isPrivateRelay);
    }

    public function testForeignAudienceIsRejected(): void
    {
        AppleIdTokenFactory::queueTokenExchange([
            'aud' => 'com.somebody.else',
            'sub' => 'apple-sub-4',
            'email' => 'real@example.com',
            'email_verified' => true,
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $this->fetch();
    }

    public function testForeignIssuerIsRejected(): void
    {
        AppleIdTokenFactory::queueTokenExchange([
            'iss' => 'https://evil.example.com',
            'sub' => 'apple-sub-5',
            'email' => 'real@example.com',
            'email_verified' => true,
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $this->fetch();
    }

    private function fetch(): SocialUserProfile
    {
        return self::getContainer()->get(SocialProfileFetcher::class)->fetch(OauthProvider::Apple, 'fake-code', null);
    }
}
