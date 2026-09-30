<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SocialLogin;

use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use SpeedPuzzling\Web\Services\SocialLogin\MicrosoftIdTokenVerifier;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginProviders;
use SpeedPuzzling\Web\Services\SocialLogin\SocialProfileFetcher;
use SpeedPuzzling\Web\Tests\ConfiguresSocialLoginProviders;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use SpeedPuzzling\Web\Tests\TestDouble\MicrosoftIdTokenFactory;
use SpeedPuzzling\Web\Tests\TestDouble\SocialLoginHttpMock;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Microsoft profile mapping (identity = oid, every personal-account email trusted)
 * and the expired-client-secret outage signal.
 */
final class SocialProfileFetcherMicrosoftTest extends KernelTestCase
{
    use ConfiguresSocialLoginProviders;

    private InMemoryLogger $logger;
    private SocialProfileFetcher $fetcher;

    protected function setUp(): void
    {
        SocialLoginHttpMock::reset();
        $this->enableSocialLoginProvider(OauthProvider::Microsoft);
        self::bootKernel();

        $container = self::getContainer();
        $container->get('social_login_state_cache')->deleteItem(MicrosoftIdTokenVerifier::JWKS_CACHE_KEY);

        $this->logger = new InMemoryLogger();
        $this->fetcher = new SocialProfileFetcher(
            $container->get(SocialLoginProviders::class),
            $container->get(MicrosoftIdTokenVerifier::class),
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        $this->restoreSocialLoginEnv();

        parent::tearDown();
    }

    public function testMapsOidEmailAndName(): void
    {
        MicrosoftIdTokenFactory::queueTokenExchange([
            'oid' => 'the-oid',
            'sub' => 'the-pairwise-sub',
            'email' => 'Player@Live.co.uk',
            'name' => 'Puzzle Player',
        ]);

        $profile = $this->fetcher->fetch(OauthProvider::Microsoft, 'code', 'verifier');

        self::assertSame(OauthProvider::Microsoft, $profile->provider);
        self::assertSame('the-oid', $profile->providerUserId);
        self::assertSame('Player@Live.co.uk', $profile->email);
        self::assertTrue($profile->emailVerified);
        self::assertSame('Puzzle Player', $profile->name);
        self::assertFalse($profile->isPrivateRelay);
    }

    public function testExternalMailboxOfPersonalAccountIsVerified(): void
    {
        MicrosoftIdTokenFactory::queueTokenExchange(['email' => 'player@gmail.com']);

        $profile = $this->fetcher->fetch(OauthProvider::Microsoft, 'code', 'verifier');

        self::assertSame('player@gmail.com', $profile->email);
        self::assertTrue($profile->emailVerified);
    }

    public function testMissingEmailIsUnverified(): void
    {
        MicrosoftIdTokenFactory::queueTokenExchange(['email' => null]);

        $profile = $this->fetcher->fetch(OauthProvider::Microsoft, 'code', 'verifier');

        self::assertNull($profile->email);
        self::assertFalse($profile->emailVerified);
    }

    public function testExpiredClientSecretIsLoggedAsAnOutage(): void
    {
        SocialLoginHttpMock::queue(new Response(401, ['Content-Type' => 'application/json'], json_encode([
            'error' => 'invalid_client',
            'error_description' => "AADSTS7000222: The provided client secret keys for app '" . MicrosoftIdTokenFactory::CLIENT_ID . "' are expired.",
        ], JSON_THROW_ON_ERROR)));

        try {
            $this->fetcher->fetch(OauthProvider::Microsoft, 'code', 'verifier');
            self::fail('The exchange must fail');
        } catch (IdentityProviderException) {
        }

        self::assertTrue($this->logger->hasRecord('error', 'Microsoft client secret expired'));
    }

    public function testOtherTokenErrorsAreNotReportedAsAnExpiredSecret(): void
    {
        SocialLoginHttpMock::queue(new Response(400, ['Content-Type' => 'application/json'], json_encode([
            'error' => 'invalid_grant',
            'error_description' => 'AADSTS70008: The provided authorization code or refresh token has expired.',
        ], JSON_THROW_ON_ERROR)));

        try {
            $this->fetcher->fetch(OauthProvider::Microsoft, 'code', 'verifier');
            self::fail('The exchange must fail');
        } catch (IdentityProviderException) {
        }

        self::assertSame([], $this->logger->records);
    }
}
