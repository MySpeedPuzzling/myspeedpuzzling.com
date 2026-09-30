<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OauthIdentity;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Events\OauthIdentityLinked;
use SpeedPuzzling\Web\Services\SocialLogin\MicrosoftIdTokenVerifier;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginProviders;
use SpeedPuzzling\Web\Tests\ConfiguresSocialLoginProviders;
use SpeedPuzzling\Web\Tests\TestDouble\MicrosoftIdTokenFactory;
use SpeedPuzzling\Web\Tests\TestDouble\SocialLoginHttpMock;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * "Continue with Microsoft" end to end against mocked Microsoft HTTP
 * (docs/features/auth-hardening/microsoft-plan.md): /consumers endpoints,
 * PKCE S256, an id_token really signed and verified against a mocked JWKS,
 * identity keyed on `oid`, and the email of every personal account trusted
 * (consumers tenant only).
 */
final class MicrosoftLoginFlowTest extends WebTestCase
{
    use ConfiguresSocialLoginProviders;

    private const string AUTHORIZE_HOST = 'login.microsoftonline.com/consumers/oauth2/v2.0/authorize';

    protected function setUp(): void
    {
        SocialLoginHttpMock::reset();
    }

    protected function tearDown(): void
    {
        $this->restoreSocialLoginEnv();

        parent::tearDown();
    }

    public function testStartRedirectsToTheConsumersEndpointWithPkceScopesAndAccountPicker(): void
    {
        $browser = $this->microsoftBrowser();

        $browser->request('GET', '/login/social/microsoft');
        $query = $this->authorizationQuery($browser);

        self::assertSame(MicrosoftIdTokenFactory::CLIENT_ID, $query['client_id'] ?? null);
        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame('openid profile email', $query['scope'] ?? null);
        self::assertSame('select_account', $query['prompt'] ?? null);
        self::assertSame('http://localhost/login/social/microsoft/callback', $query['redirect_uri'] ?? null);
        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        self::assertIsString($query['state'] ?? null);
        self::assertNotSame('', $query['state']);

        $challenge = $query['code_challenge'] ?? null;
        self::assertIsString($challenge);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $challenge);

        // The callback sends the matching verifier to the consumers token endpoint
        MicrosoftIdTokenFactory::queueTokenExchange(['email' => 'pkce+' . bin2hex(random_bytes(4)) . '@outlook.com']);
        $browser->request('GET', "/login/social/microsoft/callback?state={$query['state']}&code=fake-code");

        $tokenRequest = SocialLoginHttpMock::sentRequests()[0] ?? null;
        self::assertNotNull($tokenRequest);
        self::assertSame('POST', $tokenRequest->getMethod());
        self::assertSame(SocialLoginProviders::MICROSOFT_TOKEN_URL, (string) $tokenRequest->getUri());

        parse_str((string) $tokenRequest->getBody(), $body);
        self::assertSame('fake-code', $body['code'] ?? null);
        self::assertSame('test-microsoft-client-secret', $body['client_secret'] ?? null);
        $verifier = $body['code_verifier'] ?? null;
        self::assertIsString($verifier);
        self::assertSame($challenge, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));

        // ... and the keys come from the consumers JWKS
        $jwksRequest = SocialLoginHttpMock::sentRequests()[1] ?? null;
        self::assertNotNull($jwksRequest);
        self::assertSame('https://login.microsoftonline.com/consumers/discovery/v2.0/keys', (string) $jwksRequest->getUri());
    }

    public function testRule1KnownOidLogsInEvenWithADifferentSub(): void
    {
        $browser = $this->microsoftBrowser();

        $suffix = bin2hex(random_bytes(4));
        $oid = "00000000-0000-0000-0000-{$suffix}0000";
        $userAccount = $this->seedAccount($browser, "ms-rule1+{$suffix}@example.com");
        $this->seedIdentity($browser, $userAccount, $oid);

        // `sub` is pairwise per app registration - a re-created registration
        // hands out new ones, the `oid` stays: the identity key is the oid
        $this->signInWith($browser, [
            'oid' => $oid,
            'sub' => 'a-brand-new-pairwise-sub-' . $suffix,
            'email' => "whatever+{$suffix}@outlook.com",
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('my-profile', (string) $browser->getResponse()->headers->get('Location'));
        $this->assertSignedInAs($browser, $userAccount);

        $authenticator = $browser->getContainer()->get(Connection::class)->fetchOne(
            "SELECT authenticator FROM auth_audit_log WHERE event_type = 'oauth_login' AND user_account_id = :id",
            ['id' => $userAccount->id->toString()],
        );
        self::assertSame('social:microsoft', $authenticator);
    }

    public function testRule2OutlookAddressAutoLinksAndQueuesTheNotice(): void
    {
        $browser = $this->microsoftBrowser();

        $suffix = bin2hex(random_bytes(4));
        $email = "ms-rule2+{$suffix}@outlook.com";
        $userAccount = $this->seedAccount($browser, $email);
        $oid = "00000000-0000-0000-0002-{$suffix}0000";

        $this->signInWith($browser, ['oid' => $oid, 'email' => $email]);

        self::assertResponseRedirects();
        $this->assertLinkNoticeQueued($browser, $userAccount);
        $this->assertSignedInAs($browser, $userAccount);

        $linkedAccount = $browser->getContainer()->get(Connection::class)->fetchOne(
            "SELECT user_account_id FROM oauth_identity WHERE provider = 'microsoft' AND provider_user_id = :oid",
            ['oid' => $oid],
        );
        self::assertSame($userAccount->id->toString(), $linkedAccount, 'Rule 2 stores the oid as the identity key');
    }

    /**
     * An external address (Gmail, iCloud) used as a personal Microsoft
     * account's user name is trusted too: Microsoft account sign-up confirms
     * it with a code, and only consumers-tenant tokens get this far
     * (owner decision 2026-09-30, microsoft-plan.md §D3).
     *
     * @return iterable<string, array{string}>
     */
    public static function externalMailboxDomains(): iterable
    {
        yield 'gmail' => ['gmail.com'];
        yield 'icloud' => ['icloud.com'];
    }

    #[DataProvider('externalMailboxDomains')]
    public function testRule2ExternalAddressAutoLinksAndQueuesTheNotice(string $domain): void
    {
        $browser = $this->microsoftBrowser();

        $suffix = bin2hex(random_bytes(4));
        $email = "ms-rule2x+{$suffix}@{$domain}";
        $userAccount = $this->seedAccount($browser, $email);
        $oid = "00000000-0000-0000-0003-{$suffix}0000";

        $this->signInWith($browser, ['oid' => $oid, 'email' => $email]);

        self::assertResponseRedirects();
        $this->assertLinkNoticeQueued($browser, $userAccount);
        $this->assertSignedInAs($browser, $userAccount);
        self::assertSame(1, $this->identityCount($browser, $oid));
    }

    public function testRule4ConsumerMailboxCreatesAVerifiedAccount(): void
    {
        $browser = $this->microsoftBrowser();

        $suffix = bin2hex(random_bytes(4));
        $email = "ms-rule4+{$suffix}@Hotmail.co.uk";
        $oid = "00000000-0000-0000-0004-{$suffix}0000";

        $this->signInWith($browser, ['oid' => $oid, 'email' => $email, 'name' => 'Hotmail Person']);

        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringContainsString('/register/social?token=', $location);

        $crawler = $browser->request('GET', $location);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Microsoft', $crawler->text());

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $browser->request('POST', '/register/social', ['token' => $query['token'] ?? '']);
        self::assertResponseRedirects();
        self::assertStringContainsString('/welcome', (string) $browser->getResponse()->headers->get('Location'));

        self::assertCount(0, self::getMailerMessages(), 'A Microsoft account e-mail needs no confirmation');

        $connection = $browser->getContainer()->get(Connection::class);

        /** @var false|array{id: string, email_verified_at: null|string} $accountRow */
        $accountRow = $connection->fetchAssociative(
            'SELECT id, email_verified_at FROM user_account WHERE lower(email) = lower(:email)',
            ['email' => $email],
        );
        self::assertNotFalse($accountRow);
        self::assertNotNull($accountRow['email_verified_at']);
        self::assertSame($accountRow['id'], $connection->fetchOne(
            "SELECT user_account_id FROM oauth_identity WHERE provider = 'microsoft' AND provider_user_id = :oid",
            ['oid' => $oid],
        ));
        self::assertSame('Hotmail Person', $connection->fetchOne(
            'SELECT player.name FROM player JOIN user_account ON user_account.user_id = player.user_id WHERE lower(user_account.email) = lower(:email)',
            ['email' => $email],
        ));
    }

    public function testRule4ExternalAddressCreatesAVerifiedAccountWithoutVerificationMail(): void
    {
        $browser = $this->microsoftBrowser();

        $suffix = bin2hex(random_bytes(4));
        $email = "ms-rule4x+{$suffix}@gmail.com";

        $this->signInWith($browser, ['oid' => "00000000-0000-0000-0005-{$suffix}0000", 'email' => $email]);

        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringContainsString('/register/social?token=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $browser->request('POST', '/register/social', ['token' => $query['token'] ?? '']);
        self::assertResponseRedirects();

        self::assertCount(0, self::getMailerMessages(), 'A Microsoft account e-mail needs no confirmation');
        self::assertNotNull($browser->getContainer()->get(Connection::class)->fetchOne(
            'SELECT email_verified_at FROM user_account WHERE email = :email',
            ['email' => $email],
        ));
    }

    public function testMissingEmailClaimIsRefused(): void
    {
        $browser = $this->microsoftBrowser();

        // Phone-number-only Microsoft accounts have no email
        $this->signInWith($browser, ['oid' => '00000000-0000-0000-0006-' . bin2hex(random_bytes(6))]);

        self::assertResponseRedirects('/login');
        $crawler = $browser->followRedirect();
        self::assertStringContainsString('Microsoft did not share an email address with us', $crawler->text());
        $this->assertNotLoggedIn($browser);
    }

    public function testCancelAtMicrosoftShowsFriendlyMessage(): void
    {
        $browser = $this->microsoftBrowser();

        $state = $this->startFlow($browser);
        $browser->request('GET', "/login/social/microsoft/callback?state={$state}&error=access_denied&error_description=The+user+has+denied+access");

        self::assertResponseRedirects('/login');
        $crawler = $browser->followRedirect();
        self::assertSame('You cancelled signing in with Microsoft. Nothing was changed.', $crawler->filter('.alert-danger')->text());
    }

    public function testIdTokenFromAWorkTenantSignsNobodyIn(): void
    {
        $browser = $this->microsoftBrowser();

        $suffix = bin2hex(random_bytes(4));
        $email = "ms-tenant+{$suffix}@outlook.com";
        $this->seedAccount($browser, $email);

        // Microsoft never sends this for /consumers - defence in depth against
        // an Entra tenant minting a token with somebody else's address
        $this->signInWith($browser, [
            'oid' => "00000000-0000-0000-0007-{$suffix}0000",
            'email' => $email,
            'tid' => '72f988bf-86f1-41af-91ab-2d7cd011db47',
            'iss' => 'https://login.microsoftonline.com/72f988bf-86f1-41af-91ab-2d7cd011db47/v2.0',
        ]);

        self::assertResponseRedirects('/login');
        $crawler = $browser->followRedirect();
        self::assertSame(
            "Signing in with Microsoft didn't work. Please try again, or sign in with your e-mail.",
            $crawler->filter('.alert-danger')->text(),
        );
        $this->assertNotLoggedIn($browser);
    }

    public function testConnectFromSettingsLinksAnyAddress(): void
    {
        $browser = $this->microsoftBrowser();

        $suffix = bin2hex(random_bytes(4));
        $userAccount = $this->seedAccount($browser, "ms-rule5+{$suffix}@example.com");
        $browser->loginUser($userAccount, 'main');

        $browser->request('GET', '/account/social/microsoft/connect');
        $query = $this->authorizationQuery($browser);
        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        $state = $query['state'] ?? null;
        self::assertIsString($state);

        // Rule 5: the signed-in account links whatever address Microsoft sends
        $oid = "00000000-0000-0000-0008-{$suffix}0000";
        MicrosoftIdTokenFactory::queueTokenExchange(['oid' => $oid, 'email' => "other+{$suffix}@gmail.com"]);
        $browser->request('GET', "/login/social/microsoft/callback?state={$state}&code=fake-code");

        self::assertResponseStatusCodeSame(303);
        self::assertStringContainsString('/connect/social/microsoft/finish/', (string) $browser->getResponse()->headers->get('Location'));
        $browser->followRedirect();
        self::assertResponseRedirects();
        self::assertStringContainsString('social_link_result=connected', (string) $browser->getResponse()->headers->get('Location'));

        $this->assertLinkNoticeQueued($browser, $userAccount);
        self::assertSame(1, $this->identityCount($browser, $oid));
    }

    public function testNotConfiguredMeansNoButtonAndNoRoutes(): void
    {
        // Repo defaults: no Microsoft credentials
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a.btn-microsoft-signin'));
        self::assertStringNotContainsString('Outlook.com, Hotmail', $crawler->text());

        $browser->request('GET', '/login/social/microsoft');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/login/social/microsoft/callback?state=abc&code=x');
        self::assertResponseStatusCodeSame(404);
    }

    public function testSecretWithoutClientIdIsNotConfigured(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Microsoft);
        $this->overrideSocialLoginEnv('MICROSOFT_CLIENT_SECRET', '');
        $browser = self::createClient();

        $browser->request('GET', '/login/social/microsoft');
        self::assertResponseStatusCodeSame(404);
    }

    private function microsoftBrowser(): KernelBrowser
    {
        $this->enableSocialLoginProvider(OauthProvider::Microsoft);
        $browser = self::createClient();

        // Keys cached by an earlier run were signed with another throwaway key
        $browser->getContainer()->get('social_login_state_cache')
            ->deleteItem(MicrosoftIdTokenVerifier::JWKS_CACHE_KEY);

        return $browser;
    }

    /**
     * Runs start + callback with an id_token carrying these claims.
     *
     * @param array<string, mixed> $claims
     */
    private function signInWith(KernelBrowser $browser, array $claims): void
    {
        $state = $this->startFlow($browser);
        MicrosoftIdTokenFactory::queueTokenExchange($claims);
        $browser->request('GET', "/login/social/microsoft/callback?state={$state}&code=fake-code");
    }

    private function startFlow(KernelBrowser $browser): string
    {
        $browser->request('GET', '/login/social/microsoft');
        $state = $this->authorizationQuery($browser)['state'] ?? null;
        self::assertIsString($state);

        return $state;
    }

    /**
     * @return array<mixed>
     */
    private function authorizationQuery(KernelBrowser $browser): array
    {
        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://' . self::AUTHORIZE_HOST . '?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    private function seedAccount(KernelBrowser $browser, string $email): UserAccount
    {
        $userId = 'msp|' . Uuid::uuid7()->toString();

        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());
        $userAccount->markEmailVerified(new DateTimeImmutable());
        $userAccount->changePassword('hash');

        $player = new Player(Uuid::uuid7(), 'MS' . bin2hex(random_bytes(3)), $userId, null, new DateTimeImmutable());

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->persist($player);
        $entityManager->flush();

        return $userAccount;
    }

    private function seedIdentity(KernelBrowser $browser, UserAccount $userAccount, string $oid): void
    {
        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new OauthIdentity(
            id: Uuid::uuid7(),
            userAccount: $userAccount,
            provider: OauthProvider::Microsoft,
            providerUserId: $oid,
            emailAtLink: $userAccount->email,
            linkedAt: new DateTimeImmutable(),
        ));
        $entityManager->flush();
    }

    private function identityCount(KernelBrowser $browser, string $oid): int
    {
        $count = $browser->getContainer()->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM oauth_identity WHERE provider = 'microsoft' AND provider_user_id = :oid",
            ['oid' => $oid],
        );
        assert(is_int($count) || is_string($count));

        return (int) $count;
    }

    private function assertLinkNoticeQueued(KernelBrowser $browser, UserAccount $userAccount): void
    {
        $transport = $browser->getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $notices = [];

        foreach ($transport->getSent() as $envelope) {
            $event = $envelope->getMessage();

            if ($event instanceof OauthIdentityLinked && $event->userAccountId->equals($userAccount->id)) {
                $notices[] = $event;
            }
        }

        self::assertCount(1, $notices, 'Linking must queue exactly one security notice for the owner');
        self::assertSame(OauthProvider::Microsoft, $notices[0]->provider);
    }

    private function assertSignedInAs(KernelBrowser $browser, UserAccount $userAccount): void
    {
        $token = $browser->getContainer()->get(TokenStorageInterface::class)->getToken();
        self::assertNotNull($token);
        self::assertSame($userAccount->userId, $token->getUserIdentifier());
    }

    private function assertNotLoggedIn(KernelBrowser $browser): void
    {
        $browser->request('GET', '/en/edit-profile');
        self::assertResponseStatusCodeSame(302);
    }
}
