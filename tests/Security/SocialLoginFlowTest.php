<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OauthIdentity;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestDouble\SocialLoginHttpMock;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end social login flows against mocked provider HTTP (the league
 * providers talk to SocialLoginHttpMock, wired in config/services_test.php).
 * The OAuth dance is driven for real: the start route mints the state, the
 * callback exchanges the code, the resolver applies the settled linking rules.
 *
 * Emails/subjects are randomized per run - the audit log and the state cache
 * live outside the DAMA transaction rollback.
 */
final class SocialLoginFlowTest extends WebTestCase
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

    public function testGoogleRule1KnownIdentityLogsIn(): void
    {
        $this->enableGooglePublicly();
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $userAccount = $this->seedAccount($browser, "rule1+{$suffix}@example.com", password: 'hash');
        $this->seedIdentity($browser, $userAccount, OauthProvider::Google, "g-rule1-{$suffix}");

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');

        $this->queueGoogleExchange("g-rule1-{$suffix}", "whatever+{$suffix}@gmail.com", emailVerified: true);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        self::assertResponseRedirects();
        self::assertStringContainsString('my-profile', (string) $browser->getResponse()->headers->get('Location'));
        $this->assertLoggedIn($browser);

        $connection = $browser->getContainer()->get(Connection::class);

        $lastUsedAt = $connection->fetchOne(
            'SELECT last_used_at FROM oauth_identity WHERE provider_user_id = :sub',
            ['sub' => "g-rule1-{$suffix}"],
        );
        self::assertNotNull($lastUsedAt, 'Rule 1 login must stamp last_used_at');

        $authenticator = $connection->fetchOne(
            "SELECT authenticator FROM auth_audit_log WHERE event_type = 'oauth_login' AND user_account_id = :id",
            ['id' => $userAccount->id->toString()],
        );
        self::assertSame('social:google', $authenticator);
    }

    /**
     * The destination survives the whole OAuth round-trip in the cache-backed
     * state payload, not the session - which is the only thing that works for
     * Apple, whose callback is a cross-site form_post and so arrives without
     * SameSite=Lax cookies.
     */
    public function testReturnUrlSurvivesTheOauthRoundTrip(): void
    {
        $this->enableGooglePublicly();
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $userAccount = $this->seedAccount($browser, "ret1+{$suffix}@example.com", password: 'hash');
        $this->seedIdentity($browser, $userAccount, OauthProvider::Google, "g-ret1-{$suffix}");

        $state = $this->startFlow($browser, 'google', 'accounts.google.com', returnUrl: '/en/marketplace');

        $this->queueGoogleExchange("g-ret1-{$suffix}", "whatever+{$suffix}@gmail.com", emailVerified: true);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        self::assertResponseRedirects('/en/marketplace');
        $this->assertLoggedIn($browser);
    }

    public function testOffSiteReturnUrlNeverEntersTheOauthState(): void
    {
        $this->enableGooglePublicly();
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $userAccount = $this->seedAccount($browser, "ret2+{$suffix}@example.com", password: 'hash');
        $this->seedIdentity($browser, $userAccount, OauthProvider::Google, "g-ret2-{$suffix}");

        // Rejected at the start route, so the state payload only ever holds a
        // safe path and the callback has nothing hostile to redirect to
        $state = $this->startFlow($browser, 'google', 'accounts.google.com', returnUrl: 'https://evil.example.com');

        $this->queueGoogleExchange("g-ret2-{$suffix}", "whatever+{$suffix}@gmail.com", emailVerified: true);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('evil.example.com', $location);
        self::assertStringContainsString('my-profile', $location);
    }

    public function testGoogleRule2VerifiedEmailAutoLinksAndLogsIn(): void
    {
        $this->enableGooglePublicly();
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $email = "rule2+{$suffix}@example.com";
        $userAccount = $this->seedAccount($browser, $email, password: 'hash');

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');

        $this->queueGoogleExchange("g-rule2-{$suffix}", $email, emailVerified: true);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        self::assertResponseRedirects();
        $this->assertLoggedIn($browser);

        $connection = $browser->getContainer()->get(Connection::class);

        /** @var false|array{user_account_id: string, email_at_link: string, last_used_at: null|string} $identityRow */
        $identityRow = $connection->fetchAssociative(
            'SELECT user_account_id, email_at_link, last_used_at FROM oauth_identity WHERE provider_user_id = :sub',
            ['sub' => "g-rule2-{$suffix}"],
        );
        self::assertNotFalse($identityRow, 'Rule 2 must auto-link the identity');
        self::assertSame($userAccount->id->toString(), $identityRow['user_account_id']);
        self::assertSame($email, $identityRow['email_at_link']);
        self::assertNotNull($identityRow['last_used_at'], 'The auto-link happened mid-login');

        $linkedEvents = self::countRows(
            $connection,
            "SELECT COUNT(*) FROM auth_audit_log WHERE event_type = 'oauth_identity_linked' AND user_account_id = :id",
            ['id' => $userAccount->id->toString()],
        );
        self::assertSame(1, $linkedEvents);
    }

    public function testGoogleRule3UnverifiedEmailIsRefused(): void
    {
        $this->enableGooglePublicly();
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $email = "rule3+{$suffix}@example.com";
        $this->seedAccount($browser, $email, password: 'hash');

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');

        $this->queueGoogleExchange("g-rule3-{$suffix}", $email, emailVerified: false);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        self::assertResponseRedirects('/login');

        $crawler = $browser->followRedirect();
        self::assertStringContainsString(
            'Sign in with your password first, then connect Google in your profile settings.',
            $crawler->text(),
        );

        $connection = $browser->getContainer()->get(Connection::class);
        $identityCount = self::countRows(
            $connection,
            'SELECT COUNT(*) FROM oauth_identity WHERE provider_user_id = :sub',
            ['sub' => "g-rule3-{$suffix}"],
        );
        self::assertSame(0, $identityCount, 'The account-takeover guard must not link');

        $this->assertNotLoggedIn($browser);
    }

    public function testGoogleRule4ShowsInterstitialAndConfirmationCreatesAccount(): void
    {
        $this->enableGooglePublicly();
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $email = "rule4+{$suffix}@example.com";

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');

        $this->queueGoogleExchange("g-rule4-{$suffix}", $email, emailVerified: true, name: 'Rule Four');
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        // Never silent creation: the callback parks the profile and redirects
        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringContainsString('/register/social?token=', $location);

        $crawler = $browser->request('GET', $location);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($email, $crawler->text());
        self::assertStringContainsString('Google', $crawler->text());

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $token = $query['token'];
        self::assertIsString($token);

        $browser->request('POST', '/register/social', ['token' => $token]);
        self::assertResponseRedirects();
        self::assertStringContainsString('my-profile', (string) $browser->getResponse()->headers->get('Location'));
        $this->assertLoggedIn($browser);

        $connection = $browser->getContainer()->get(Connection::class);

        /** @var false|array{id: string, password: null|string, email_verified_at: null|string} $accountRow */
        $accountRow = $connection->fetchAssociative(
            'SELECT id, password, email_verified_at FROM user_account WHERE email = :email',
            ['email' => $email],
        );
        self::assertNotFalse($accountRow);
        self::assertNull($accountRow['password'], 'Social accounts start password-less');
        self::assertNotNull($accountRow['email_verified_at'], 'Provider-verified email carries over');

        $identityAccount = $connection->fetchOne(
            'SELECT user_account_id FROM oauth_identity WHERE provider_user_id = :sub',
            ['sub' => "g-rule4-{$suffix}"],
        );
        self::assertSame($accountRow['id'], $identityAccount);

        $playerName = $connection->fetchOne(
            'SELECT name FROM player WHERE email = :email',
            ['email' => $email],
        );
        self::assertSame('Rule Four', $playerName);
    }

    public function testAdminOnlyStageDeniesNonAdminWithGenericFailure(): void
    {
        // Provider on, SOCIAL_LOGIN_ADMIN_ONLY stays at the repo default (ON)
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_GOOGLE_ENABLED', true);
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $userAccount = $this->seedAccount($browser, "adminonly+{$suffix}@example.com", password: 'hash', isAdmin: false);
        $this->seedIdentity($browser, $userAccount, OauthProvider::Google, "g-adminonly-{$suffix}");

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');

        $this->queueGoogleExchange("g-adminonly-{$suffix}", "adminonly+{$suffix}@example.com", emailVerified: true);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        self::assertResponseRedirects('/login');
        $this->assertNotLoggedIn($browser);

        $connection = $browser->getContainer()->get(Connection::class);
        $lastUsedAt = $connection->fetchOne(
            'SELECT last_used_at FROM oauth_identity WHERE provider_user_id = :sub',
            ['sub' => "g-adminonly-{$suffix}"],
        );
        self::assertNull($lastUsedAt, 'A denied login must not touch the identity');
    }

    public function testAdminOnlyStageAllowsAdmins(): void
    {
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_GOOGLE_ENABLED', true);
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $userAccount = $this->seedAccount($browser, "adminyes+{$suffix}@example.com", password: 'hash', isAdmin: true);
        $this->seedIdentity($browser, $userAccount, OauthProvider::Google, "g-adminyes-{$suffix}");

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');

        $this->queueGoogleExchange("g-adminyes-{$suffix}", "adminyes+{$suffix}@example.com", emailVerified: true);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        self::assertResponseRedirects();
        self::assertStringContainsString('my-profile', (string) $browser->getResponse()->headers->get('Location'));
        $this->assertLoggedIn($browser);
    }

    public function testAdminOnlyStageDisablesRegistrationEntirely(): void
    {
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_GOOGLE_ENABLED', true);
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');

        $this->queueGoogleExchange("g-noreg-{$suffix}", "noreg+{$suffix}@example.com", emailVerified: true);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        // Generic failure, no interstitial - the feature must not reveal itself
        self::assertResponseRedirects('/login');
        $this->assertNotLoggedIn($browser);

        $connection = $browser->getContainer()->get(Connection::class);
        $accountCount = self::countRows(
            $connection,
            'SELECT COUNT(*) FROM user_account WHERE email = :email',
            ['email' => "noreg+{$suffix}@example.com"],
        );
        self::assertSame(0, $accountCount);
    }

    public function testDisabledProviderIs404(): void
    {
        // Repo defaults: every provider flag OFF
        $browser = self::createClient();

        $browser->request('GET', '/login/social/google');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/login/social/google/callback?state=abc&code=x');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/login/social/nonsense');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownStateRedirectsToLoginWithExpiredFlag(): void
    {
        $this->enableGooglePublicly();
        $browser = self::createClient();

        $browser->request('GET', '/login/social/google/callback?state=' . str_repeat('ab', 16) . '&code=x');

        self::assertResponseRedirects('/login?social=expired');
    }

    public function testFacebookDeniedEmailPermissionIsRefused(): void
    {
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_FACEBOOK_ENABLED', true);
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_ADMIN_ONLY', false);
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));

        $state = $this->startFlow($browser, 'facebook', 'facebook.com');

        // Graph answers without an email: the permission was denied
        SocialLoginHttpMock::queue(
            self::jsonResponse(['access_token' => 'fb-token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            self::jsonResponse(['id' => "fb-noemail-{$suffix}", 'name' => 'No Email']),
        );
        $browser->request('GET', "/login/social/facebook/callback?state={$state}&code=fake-code");

        self::assertResponseRedirects('/login');

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('did not share an email address', $crawler->text());
        $this->assertNotLoggedIn($browser);
    }

    public function testConnectFromSettingsLinksDifferentEmailProviderAccount(): void
    {
        $this->enableGooglePublicly();
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $userAccount = $this->seedAccount($browser, "rule5+{$suffix}@example.com", password: 'hash');
        $browser->loginUser($userAccount, 'main');

        $browser->request('GET', '/account/social/google/connect');
        self::assertResponseRedirects();
        $state = $this->stateFromLocation($browser, 'accounts.google.com');

        // Rule 5: completely different provider email - links anyway
        $this->queueGoogleExchange("g-rule5-{$suffix}", "different+{$suffix}@gmail.com", emailVerified: true);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringContainsString('social_link_result=connected', $location);
        self::assertStringContainsString('social_link_provider=google', $location);

        $connection = $browser->getContainer()->get(Connection::class);

        /** @var false|array{user_account_id: string, email_at_link: string, last_used_at: null|string} $identityRow */
        $identityRow = $connection->fetchAssociative(
            'SELECT user_account_id, email_at_link, last_used_at FROM oauth_identity WHERE provider_user_id = :sub',
            ['sub' => "g-rule5-{$suffix}"],
        );
        self::assertNotFalse($identityRow);
        self::assertSame($userAccount->id->toString(), $identityRow['user_account_id']);
        self::assertSame("different+{$suffix}@gmail.com", $identityRow['email_at_link']);
        self::assertNull($identityRow['last_used_at'], 'A settings link is not a sign-in');

        // The edit-profile page renders the feedback and the disconnect form
        $crawler = $browser->request('GET', $location);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Connected!', $crawler->text());

        $form = $crawler->filter('form[action$="/account/social/google/disconnect"] button')->form();
        $browser->submit($form);
        self::assertResponseRedirects();

        $identityCount = self::countRows(
            $connection,
            'SELECT COUNT(*) FROM oauth_identity WHERE provider_user_id = :sub',
            ['sub' => "g-rule5-{$suffix}"],
        );
        self::assertSame(0, $identityCount);
    }

    public function testAppleFormPostCallbackRule4CapturesTheOneShotName(): void
    {
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_APPLE_ENABLED', true);
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_ADMIN_ONLY', false);
        $this->overrideAppleCredentials();
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $email = "relay+{$suffix}@privaterelay.appleid.com";
        $sub = "apple-rule4-{$suffix}";

        $state = $this->startFlow($browser, 'apple', 'appleid.apple.com');

        [$jwks, $idToken] = $this->rsaSignedIdToken([
            'iss' => 'https://appleid.apple.com',
            'aud' => 'com.myspeedpuzzling.test',
            'sub' => $sub,
            'email' => $email,
            'email_verified' => true,
            'iat' => time(),
            'exp' => time() + 600,
        ]);

        // Token endpoint answers first, then AppleAccessToken fetches the JWKs
        SocialLoginHttpMock::queue(
            self::jsonResponse([
                'access_token' => 'apple-at',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'refresh_token' => 'apple-rt',
                'id_token' => $idToken,
            ]),
            new HttpResponse(200, ['Content-Type' => 'application/json'], $jwks),
        );

        // Cross-site form_post: a POST without any session cookie, carrying the
        // one-shot `user` payload of the first authorization
        $browser->request('POST', '/login/social/apple/callback', [
            'state' => $state,
            'code' => 'fake-apple-code',
            'user' => json_encode(['name' => ['firstName' => 'Jane', 'lastName' => 'Appleseed'], 'email' => $email]),
        ]);

        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringContainsString('/register/social?token=', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $token = $query['token'];
        self::assertIsString($token);

        $browser->request('POST', '/register/social', ['token' => $token]);
        self::assertResponseRedirects();
        $this->assertLoggedIn($browser);

        $connection = $browser->getContainer()->get(Connection::class);

        $playerName = $connection->fetchOne('SELECT name FROM player WHERE email = :email', ['email' => $email]);
        self::assertSame('Jane Appleseed', $playerName, 'The first-authorization name must be captured - it never comes again');

        /** @var false|array{provider: string, email_at_link: string} $identityRow */
        $identityRow = $connection->fetchAssociative(
            'SELECT provider, email_at_link FROM oauth_identity WHERE provider_user_id = :sub',
            ['sub' => $sub],
        );
        self::assertNotFalse($identityRow);
        self::assertSame('apple', $identityRow['provider']);
        self::assertSame($email, $identityRow['email_at_link']);
    }

    private function enableGooglePublicly(): void
    {
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_GOOGLE_ENABLED', true);
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_ADMIN_ONLY', false);
    }

    private function overrideAppleCredentials(): void
    {
        // A throwaway EC P-256 key: the provider signs its ES256 client-secret
        // JWT with it for real, only the HTTP endpoints are mocked
        $ecKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        assert($ecKey !== false);
        openssl_pkey_export($ecKey, $ecPem);
        assert(is_string($ecPem));

        $this->overrideStringEnv('APPLE_CLIENT_ID', 'com.myspeedpuzzling.test');
        $this->overrideStringEnv('APPLE_TEAM_ID', 'TESTTEAM01');
        $this->overrideStringEnv('APPLE_KEY_ID', 'TESTKEY001');
        $this->overrideStringEnv('APPLE_PRIVATE_KEY', $ecPem);
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return array{0: string, 1: string} JWKS body + RS256-signed id_token
     */
    private function rsaSignedIdToken(array $claims): array
    {
        $rsaKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        assert($rsaKey !== false);
        openssl_pkey_export($rsaKey, $rsaPem);
        assert(is_string($rsaPem));
        $details = openssl_pkey_get_details($rsaKey);
        assert(is_array($details));
        $rsa = $details['rsa'];
        assert(is_array($rsa) && is_string($rsa['n']) && is_string($rsa['e']));

        $jwks = json_encode([
            'keys' => [
                [
                    'kty' => 'RSA',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'kid' => 'test-kid',
                    'n' => self::base64Url($rsa['n']),
                    'e' => self::base64Url($rsa['e']),
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        return [$jwks, JWT::encode($claims, $rsaPem, 'RS256', 'test-kid')];
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
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

    private function startFlow(KernelBrowser $browser, string $provider, string $expectedHost, null|string $returnUrl = null): string
    {
        $browser->request(
            'GET',
            '/login/social/' . $provider . ($returnUrl === null ? '' : '?return=' . rawurlencode($returnUrl)),
        );
        self::assertResponseRedirects();

        return $this->stateFromLocation($browser, $expectedHost);
    }

    private function stateFromLocation(KernelBrowser $browser, string $expectedHost): string
    {
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringContainsString($expectedHost, $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $state = $query['state'] ?? null;
        self::assertIsString($state);
        self::assertNotSame('', $state);

        return $state;
    }

    private function queueGoogleExchange(string $sub, string $email, bool $emailVerified, string $name = 'Google User'): void
    {
        SocialLoginHttpMock::queue(
            self::jsonResponse(['access_token' => 'google-token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            self::jsonResponse(['sub' => $sub, 'email' => $email, 'email_verified' => $emailVerified, 'name' => $name]),
        );
    }

    /**
     * @param array<string, string> $params
     */
    private static function countRows(Connection $connection, string $sql, array $params): int
    {
        $count = $connection->fetchOne($sql, $params);
        assert(is_int($count) || is_string($count));

        return (int) $count;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function jsonResponse(array $payload): HttpResponse
    {
        return new HttpResponse(200, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function seedAccount(KernelBrowser $browser, string $email, null|string $password, bool $isAdmin = false): UserAccount
    {
        $userId = 'msp|' . Uuid::uuid7()->toString();

        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());

        if ($password !== null) {
            $userAccount->changePassword($password);
        }

        $player = new Player(Uuid::uuid7(), 'SL' . bin2hex(random_bytes(3)), $userId, $email, null, new DateTimeImmutable());
        $player->isAdmin = $isAdmin;

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->persist($player);
        $entityManager->flush();

        return $userAccount;
    }

    private function seedIdentity(KernelBrowser $browser, UserAccount $userAccount, OauthProvider $provider, string $providerUserId): void
    {
        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new OauthIdentity(
            id: Uuid::uuid7(),
            userAccount: $userAccount,
            provider: $provider,
            providerUserId: $providerUserId,
            emailAtLink: $userAccount->email,
            linkedAt: new DateTimeImmutable(),
        ));
        $entityManager->flush();
    }

    private function assertLoggedIn(KernelBrowser $browser): void
    {
        $browser->request('GET', '/en/edit-profile');
        self::assertResponseIsSuccessful();
    }

    private function assertNotLoggedIn(KernelBrowser $browser): void
    {
        $browser->request('GET', '/en/edit-profile');
        self::assertResponseStatusCodeSame(302);
    }
}
