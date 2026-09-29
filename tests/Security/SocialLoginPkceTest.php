<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use GuzzleHttp\Psr7\Response as HttpResponse;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestDouble\AppleIdTokenFactory;
use SpeedPuzzling\Web\Tests\TestDouble\SocialLoginHttpMock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PKCE really happens for Google: the league Google provider ignores a
 * `pkceMethod` constructor option, so for a while production sent no
 * code_challenge at all. These tests pin the wire format end to end - the
 * challenge on the consent URL, and the matching verifier on the token
 * exchange after the verifier travelled through the cache-backed state.
 *
 * Facebook (PKCE documented only for Meta's OIDC flow) and Apple (not
 * documented for the web flow) deliberately run without it.
 */
final class SocialLoginPkceTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    /** @var array<string, string|false> */
    private array $originalStringEnv = [];

    protected function setUp(): void
    {
        SocialLoginHttpMock::reset();
    }

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        foreach ($this->originalStringEnv as $name => $original) {
            if ($original === false) {
                unset($_ENV[$name], $_SERVER[$name]);

                continue;
            }

            $_ENV[$name] = $original;
            $_SERVER[$name] = $original;
        }

        $this->originalStringEnv = [];

        parent::tearDown();
    }

    public function testGoogleLoginSendsS256ChallengeAndTheMatchingVerifier(): void
    {
        $this->enableProvider('SOCIAL_LOGIN_GOOGLE_ENABLED');
        $browser = self::createClient();

        $browser->request('GET', '/login/social/google');
        $query = $this->authorizationQuery($browser, 'accounts.google.com');

        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        $challenge = $query['code_challenge'] ?? null;
        self::assertIsString($challenge);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $challenge);

        $state = $query['state'] ?? null;
        self::assertIsString($state);

        $this->queueGoogleExchange();
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        $this->assertTokenExchangeCarriesVerifierFor($challenge);
    }

    public function testGoogleConnectFromSettingsRunsPkceToo(): void
    {
        $this->enableProvider('SOCIAL_LOGIN_GOOGLE_ENABLED');
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/account/social/google/connect');
        $query = $this->authorizationQuery($browser, 'accounts.google.com');

        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        $challenge = $query['code_challenge'] ?? null;
        self::assertIsString($challenge);

        $state = $query['state'] ?? null;
        self::assertIsString($state);

        $this->queueGoogleExchange();
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        $this->assertTokenExchangeCarriesVerifierFor($challenge);
    }

    public function testEveryFlowGetsItsOwnChallenge(): void
    {
        $this->enableProvider('SOCIAL_LOGIN_GOOGLE_ENABLED');
        $browser = self::createClient();

        $browser->request('GET', '/login/social/google');
        $first = $this->authorizationQuery($browser, 'accounts.google.com')['code_challenge'] ?? null;

        $browser->request('GET', '/login/social/google');
        $second = $this->authorizationQuery($browser, 'accounts.google.com')['code_challenge'] ?? null;

        self::assertIsString($first);
        self::assertIsString($second);
        self::assertNotSame($first, $second);
    }

    public function testFacebookRunsWithoutPkce(): void
    {
        $this->enableProvider('SOCIAL_LOGIN_FACEBOOK_ENABLED');
        $browser = self::createClient();

        $browser->request('GET', '/login/social/facebook');
        $query = $this->authorizationQuery($browser, 'facebook.com');

        self::assertArrayNotHasKey('code_challenge', $query);
        self::assertArrayNotHasKey('code_challenge_method', $query);
    }

    public function testAppleRunsWithoutPkce(): void
    {
        $this->enableProvider('SOCIAL_LOGIN_APPLE_ENABLED');
        $this->overrideStringEnv('APPLE_CLIENT_ID', AppleIdTokenFactory::CLIENT_ID);
        $this->overrideStringEnv('APPLE_TEAM_ID', 'TESTTEAM01');
        $this->overrideStringEnv('APPLE_KEY_ID', 'TESTKEY001');
        $this->overrideStringEnv('APPLE_PRIVATE_KEY', AppleIdTokenFactory::clientSecretKeyPem());
        $browser = self::createClient();

        $browser->request('GET', '/login/social/apple');
        $query = $this->authorizationQuery($browser, 'appleid.apple.com');

        self::assertArrayNotHasKey('code_challenge', $query);
        self::assertArrayNotHasKey('code_challenge_method', $query);
    }

    private function assertTokenExchangeCarriesVerifierFor(string $challenge): void
    {
        $requests = SocialLoginHttpMock::sentRequests();
        self::assertNotEmpty($requests, 'The callback must exchange the code');

        $tokenRequest = $requests[0];
        self::assertSame('POST', $tokenRequest->getMethod());
        self::assertStringContainsString('oauth2.googleapis.com/token', (string) $tokenRequest->getUri());

        parse_str((string) $tokenRequest->getBody(), $body);
        self::assertSame('fake-code', $body['code'] ?? null);

        $verifier = $body['code_verifier'] ?? null;
        self::assertIsString($verifier, 'The token exchange must send the PKCE verifier');
        self::assertSame(
            $challenge,
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'The verifier must hash to the challenge sent on the consent URL',
        );
    }

    private function enableProvider(string $flag): void
    {
        $this->overrideFeatureFlagEnv($flag, true);
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_ADMIN_ONLY', false);
    }

    /**
     * @return array<mixed>
     */
    private function authorizationQuery(KernelBrowser $browser, string $expectedHost): array
    {
        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringContainsString($expectedHost, $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    private function queueGoogleExchange(): void
    {
        $suffix = bin2hex(random_bytes(4));

        SocialLoginHttpMock::queue(
            self::jsonResponse(['access_token' => 'google-token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            self::jsonResponse([
                'sub' => "g-pkce-{$suffix}",
                'email' => "pkce+{$suffix}@gmail.com",
                'email_verified' => true,
                'name' => 'Google User',
            ]),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function jsonResponse(array $payload): HttpResponse
    {
        return new HttpResponse(200, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function overrideStringEnv(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->originalStringEnv)) {
            $original = $_ENV[$name] ?? false;
            $this->originalStringEnv[$name] = is_string($original) ? $original : false;
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
