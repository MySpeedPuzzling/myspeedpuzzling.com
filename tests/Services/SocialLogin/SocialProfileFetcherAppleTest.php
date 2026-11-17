<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SocialLogin;

use Firebase\JWT\ExpiredException;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\Exception\AppleAccessDeniedException;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Exceptions\SocialProviderUnavailable;
use SpeedPuzzling\Web\Services\SocialLogin\SocialProfileFetcher;
use SpeedPuzzling\Web\Tests\TestClock;
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

    public function testIdTokenExpiredOnTheAppClockIsRejected(): void
    {
        // Expired a second before the app's now (the other tests' tokens are valid only around the frozen instant)
        AppleIdTokenFactory::queueTokenExchange([
            'sub' => 'apple-sub-6',
            'email' => 'real@example.com',
            'email_verified' => true,
            'iat' => TestClock::timestamp() - 600,
            'exp' => TestClock::timestamp() - 1,
        ]);

        $this->expectException(ExpiredException::class);
        $this->fetch();
    }

    public function testServerErrorIsRetriedOnce(): void
    {
        SocialLoginHttpMock::queue(self::tokenError(400, 'server_error'));
        AppleIdTokenFactory::queueTokenExchange(['sub' => 'apple-sub-7', 'email' => 'real@example.com', 'email_verified' => true]);

        self::assertSame('apple-sub-7', $this->fetch()->providerUserId);
        // the failed exchange, the retried one and the JWKS fetch
        self::assertCount(3, SocialLoginHttpMock::sentRequests());
    }

    public function testServerErrorTwiceMeansTheProviderIsUnavailable(): void
    {
        SocialLoginHttpMock::queue(self::tokenError(400, 'server_error'), self::tokenError(503, 'temporarily_unavailable'));

        try {
            $this->fetch();
            self::fail('The exchange must fail');
        } catch (SocialProviderUnavailable $exception) {
            self::assertSame(OauthProvider::Apple, $exception->provider);
            self::assertSame('temporarily_unavailable', $exception->providerError);
        }

        self::assertCount(2, SocialLoginHttpMock::sentRequests());
    }

    public function testBareServerFailureMeansTheProviderIsUnavailable(): void
    {
        SocialLoginHttpMock::queue(self::tokenError(502, null), self::tokenError(502, null));

        $this->expectException(SocialProviderUnavailable::class);
        $this->fetch();
    }

    public function testFlakyInvalidClientIsRetriedOnce(): void
    {
        SocialLoginHttpMock::queue(self::tokenError(400, 'invalid_client'));
        AppleIdTokenFactory::queueTokenExchange(['sub' => 'apple-sub-8', 'email' => 'real@example.com', 'email_verified' => true]);

        self::assertSame('apple-sub-8', $this->fetch()->providerUserId);
    }

    public function testInvalidClientTwiceStaysAPlainFailure(): void
    {
        // Possibly our own config - never dressed up as "Apple is down"
        SocialLoginHttpMock::queue(self::tokenError(400, 'invalid_client'), self::tokenError(400, 'invalid_client'));

        $this->expectException(AppleAccessDeniedException::class);
        $this->fetch();
    }

    public function testInvalidGrantIsNotRetried(): void
    {
        SocialLoginHttpMock::queue(self::tokenError(400, 'invalid_grant'));

        try {
            $this->fetch();
            self::fail('The exchange must fail');
        } catch (AppleAccessDeniedException) {
        }

        self::assertCount(1, SocialLoginHttpMock::sentRequests());
    }

    private static function tokenError(int $status, null|string $error): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode(
            $error === null ? [] : ['error' => $error],
            JSON_THROW_ON_ERROR,
        ));
    }

    private function fetch(): SocialUserProfile
    {
        return self::getContainer()->get(SocialProfileFetcher::class)->fetch(OauthProvider::Apple, 'fake-code', null);
    }
}
