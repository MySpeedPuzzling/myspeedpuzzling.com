<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OauthIdentity;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Events\OauthIdentityLinked;
use SpeedPuzzling\Web\Security\SocialLoginFailed;
use SpeedPuzzling\Web\Tests\ConfiguresSocialLoginProviders;
use SpeedPuzzling\Web\Tests\TestDouble\AppleIdTokenFactory;
use SpeedPuzzling\Web\Tests\TestDouble\SocialLoginHttpMock;
use SpeedPuzzling\Web\Value\OauthProvider;
use SpeedPuzzling\Web\Value\SocialLoginFailureReason;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
    use ConfiguresSocialLoginProviders;

    /**
     * The generic message of a refused auto-link: naming the reason would
     * tell a stranger which sign-in methods the account has.
     */
    private const string NOT_POSSIBLE_GOOGLE = "We couldn't sign you in with Google. Please sign in with your e-mail or password, then connect Google in your profile settings.";

    protected function setUp(): void
    {
        SocialLoginHttpMock::reset();
    }

    protected function tearDown(): void
    {
        $this->restoreSocialLoginEnv();

        parent::tearDown();
    }

    public function testGoogleRule1KnownIdentityLogsIn(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
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
        $this->enableSocialLoginProvider(OauthProvider::Google);
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
        $this->enableSocialLoginProvider(OauthProvider::Google);
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
        $this->enableSocialLoginProvider(OauthProvider::Google);
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
        $this->enableSocialLoginProvider(OauthProvider::Google);
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
            'Google has not confirmed that the address belongs to you.',
            $crawler->text(),
        );
        self::assertStringContainsString('then connect Google in your profile settings', $crawler->text());

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
        $this->enableSocialLoginProvider(OauthProvider::Google);
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
        // Same first screen as a native registration (docs/features/getting-started-guide.md)
        self::assertStringContainsString('/welcome', (string) $browser->getResponse()->headers->get('Location'));
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
            'SELECT player.name FROM player JOIN user_account ON user_account.user_id = player.user_id WHERE user_account.email = :email',
            ['email' => $email],
        );
        self::assertSame('Rule Four', $playerName);
    }

    public function testDisabledProviderIs404(): void
    {
        // Repo defaults: no credentials configured, Facebook flag OFF
        $browser = self::createClient();

        $browser->request('GET', '/login/social/google');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/login/social/google/callback?state=abc&code=x');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/login/social/nonsense');
        self::assertResponseStatusCodeSame(404);
    }

    public function testProviderWithIncompleteCredentialsIs404(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google, OauthProvider::Apple);
        $this->overrideSocialLoginEnv('GOOGLE_CLIENT_SECRET', '');
        $this->overrideSocialLoginEnv('APPLE_PRIVATE_KEY', '');
        $browser = self::createClient();

        $browser->request('GET', '/login/social/google');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/login/social/apple');
        self::assertResponseStatusCodeSame(404);
    }

    public function testFacebookStaysOffWhileItsFlagIsOffEvenWithCredentials(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Facebook);
        $this->overrideSocialLoginEnv('SOCIAL_LOGIN_FACEBOOK_ENABLED', '0');
        $browser = self::createClient();

        $browser->request('GET', '/login/social/facebook');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/login/social/facebook/callback?state=abc&code=x');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownStateRedirectsToLoginWithExpiredFlag(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $browser->request('GET', '/login/social/google/callback?state=' . str_repeat('ab', 16) . '&code=x');

        self::assertResponseRedirects('/login?social=expired');
    }

    public function testFacebookDeniedEmailPermissionIsRefused(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Facebook);
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
        $this->enableSocialLoginProvider(OauthProvider::Google);
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

        // The callback never links - it parks the profile and hands over to
        // the finish route, which checks who is signed in
        self::assertResponseStatusCodeSame(303);
        self::assertStringContainsString('/connect/social/google/finish/', (string) $browser->getResponse()->headers->get('Location'));

        $browser->followRedirect();
        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');

        // The owner is told about the new way into their account
        $this->assertLinkNoticeQueued($browser, $userAccount, OauthProvider::Google);
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

        // The edit-profile page turns the outcome into the site-wide flash on
        // a clean URL (a reload must not repeat it) ...
        $browser->request('GET', $location);
        self::assertResponseRedirects();
        self::assertStringNotContainsString('social_link_result', (string) $browser->getResponse()->headers->get('Location'));

        $crawler = $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main > .container .alert-success', 'Google is connected. You can now use it to sign in.');
        // ... and the card marks the freshly connected row quietly, no alert box
        self::assertAnySelectorTextContains('.card .text-success', 'Connected — you can now use it to sign in.');
        self::assertSelectorNotExists('.card .alert');

        // Once only: the next visit shows the row as usual
        $crawler = $browser->request('GET', (string) $browser->getRequest()->getUri());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('you can now use it to sign in', $crawler->text());

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
        $this->enableSocialLoginProvider(OauthProvider::Apple);
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $email = "relay+{$suffix}@privaterelay.appleid.com";
        $sub = "apple-rule4-{$suffix}";

        $state = $this->startFlow($browser, 'apple', 'appleid.apple.com');

        AppleIdTokenFactory::queueTokenExchange([
            'sub' => $sub,
            'email' => $email,
            'email_verified' => true,
            'is_private_email' => 'true',
        ]);

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

        $playerName = $connection->fetchOne(
            'SELECT player.name FROM player JOIN user_account ON user_account.user_id = player.user_id WHERE user_account.email = :email',
            ['email' => $email],
        );
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

    public function testRule2IsRefusedWhenTheExistingAccountIsUnverified(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $email = "unverified+{$suffix}@example.com";
        // Someone registered this address here and never confirmed it - an
        // email match proves nothing about who owns the account
        $this->seedAccount($browser, $email, password: 'hash', emailVerified: false);

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');

        $this->queueGoogleExchange("g-unverified-{$suffix}", $email, emailVerified: true);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        self::assertResponseRedirects('/login');
        $crawler = $browser->followRedirect();
        self::assertStringContainsString('but that address has not been verified yet', $crawler->text());
        self::assertStringContainsString('then connect Google in your profile settings', $crawler->text());

        self::assertSame(0, $this->identityCount($browser, "g-unverified-{$suffix}"));
        $this->assertNotLoggedIn($browser);
    }

    /**
     * Apple sends email_verified as a string too; the library treats "false"
     * as truthy. Our own claim reading must not.
     */
    public function testAppleStringFalseEmailVerifiedNeverAutoLinks(): void
    {
        $this->enableApplePublicly();
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $email = "applefalse+{$suffix}@example.com";
        $this->seedAccount($browser, $email, password: 'hash');

        $state = $this->startFlow($browser, 'apple', 'appleid.apple.com');

        AppleIdTokenFactory::queueTokenExchange([
            'sub' => "apple-false-{$suffix}",
            'email' => $email,
            'email_verified' => 'false',
        ]);
        $browser->request('POST', '/login/social/apple/callback', ['state' => $state, 'code' => 'fake-apple-code']);

        self::assertResponseRedirects('/login');
        $crawler = $browser->followRedirect();
        self::assertStringContainsString('Apple has not confirmed that the address belongs to you.', $crawler->text());

        self::assertSame(0, $this->identityCount($browser, "apple-false-{$suffix}"));
    }

    public function testAppleIdTokenForAnotherClientIsRejected(): void
    {
        $this->enableApplePublicly();
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $email = "appleaud+{$suffix}@example.com";
        $this->seedAccount($browser, $email, password: 'hash');

        $state = $this->startFlow($browser, 'apple', 'appleid.apple.com');

        // Validly signed by "Apple" - but issued to somebody else's app
        AppleIdTokenFactory::queueTokenExchange([
            'aud' => 'com.somebody.else',
            'sub' => "apple-aud-{$suffix}",
            'email' => $email,
            'email_verified' => true,
        ]);
        $browser->request('POST', '/login/social/apple/callback', ['state' => $state, 'code' => 'fake-apple-code']);

        self::assertResponseRedirects('/login');
        self::assertSame(0, $this->identityCount($browser, "apple-aud-{$suffix}"));
        $this->assertNotLoggedIn($browser);
    }

    /**
     * Decision 2026-09-29: a provider email explicitly marked unverified is
     * not refused - the account is created unverified and asked to confirm.
     */
    public function testRule4WithExplicitlyUnverifiedEmailCreatesUnverifiedAccountAndSendsVerification(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $email = "rule4unverified+{$suffix}@example.com";

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');

        $this->queueGoogleExchange("g-rule4u-{$suffix}", $email, emailVerified: false);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        $token = $this->registrationTokenFromLocation($browser);

        $browser->request('POST', '/register/social', ['token' => $token]);
        self::assertResponseRedirects();

        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(Email::class, $messages[0]);
        self::assertSame($email, $messages[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('/verify-email?token=', (string) $messages[0]->getHtmlBody());

        $this->assertRememberMeCookieIssued($browser);

        $verifiedAt = $browser->getContainer()->get(Connection::class)->fetchOne(
            'SELECT email_verified_at FROM user_account WHERE email = :email',
            ['email' => $email],
        );
        self::assertNull($verifiedAt);
    }

    public function testRule4WithTrustedEmailSendsNoVerificationAndRemembersTheUser(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');

        $this->queueGoogleExchange("g-rule4v-{$suffix}", "rule4verified+{$suffix}@example.com", emailVerified: true);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        $browser->request('POST', '/register/social', ['token' => $this->registrationTokenFromLocation($browser)]);
        self::assertResponseRedirects();

        self::assertCount(0, self::getMailerMessages());
        $this->assertRememberMeCookieIssued($browser);
    }

    /**
     * Forged connect: the attacker starts a connect flow from THEIR account
     * and gets the victim to complete it. The victim's provider identity must
     * never land on the attacker's account.
     */
    public function testForgedConnectCompletedByAnotherSignedInAccountLinksNothing(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $attacker = $this->seedAccount($browser, "attacker+{$suffix}@example.com", password: 'hash');
        $victim = $this->seedAccount($browser, "victim+{$suffix}@example.com", password: 'hash');

        $browser->loginUser($attacker, 'main');
        $browser->request('GET', '/account/social/google/connect');
        $state = $this->stateFromLocation($browser, 'accounts.google.com');

        // The victim's browser completes the consent and follows the callback
        $browser->loginUser($victim, 'main');
        $this->queueGoogleExchange("g-forged-{$suffix}", "victim+{$suffix}@gmail.com", emailVerified: true);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");
        self::assertResponseStatusCodeSame(303);

        $browser->followRedirect();
        self::assertResponseRedirects();
        self::assertStringContainsString('social_link_result=failed', (string) $browser->getResponse()->headers->get('Location'));

        // The failure is the site-wide error flash at the top of the page
        $browser->followRedirect();
        self::assertResponseRedirects();
        $browser->followRedirect();
        self::assertSelectorTextContains('main > .container .alert-danger', 'Connecting failed.');

        self::assertSame(0, $this->identityCount($browser, "g-forged-{$suffix}"));
    }

    public function testFinishTokenIsSingleUse(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $userAccount = $this->seedAccount($browser, "once+{$suffix}@example.com", password: 'hash');
        $browser->loginUser($userAccount, 'main');

        $browser->request('GET', '/account/social/google/connect');
        $state = $this->stateFromLocation($browser, 'accounts.google.com');

        $this->queueGoogleExchange("g-once-{$suffix}", "once+{$suffix}@gmail.com", emailVerified: true);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");
        $finishUrl = (string) $browser->getResponse()->headers->get('Location');

        $browser->request('GET', $finishUrl);
        self::assertStringContainsString('social_link_result=connected', (string) $browser->getResponse()->headers->get('Location'));

        $browser->request('GET', $finishUrl);
        self::assertStringContainsString('social_link_result=failed', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testRule2AutoLinkQueuesTheSecurityNotice(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $email = "notice+{$suffix}@example.com";
        $userAccount = $this->seedAccount($browser, $email, password: 'hash');

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');

        $this->queueGoogleExchange("g-notice-{$suffix}", $email, emailVerified: true);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        self::assertResponseRedirects();
        $this->assertLinkNoticeQueued($browser, $userAccount, OauthProvider::Google);
    }

    /**
     * The interstitial's "I already have an account": the parked provider
     * profile survives the sign-in and is connected to the account signed in to.
     */
    public function testInterstitialSignInKeepsTheProviderProfileAndConnectsIt(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $existing = $this->seedAccount($browser, "existing+{$suffix}@example.com", password: 'hash');
        $providerEmail = "other+{$suffix}@gmail.com";

        $returnPath = $this->parkThroughInterstitial($browser, "g-keep-{$suffix}", $providerEmail);

        // Signs in to the existing account (any method), then lands on the return path
        $browser->loginUser($existing, 'main');
        $browser->request('GET', $returnPath);

        self::assertResponseRedirects();
        self::assertStringContainsString('social_link_result=connected', (string) $browser->getResponse()->headers->get('Location'));
        $this->assertLinkNoticeQueued($browser, $existing, OauthProvider::Google);

        $connection = $browser->getContainer()->get(Connection::class);
        $identityAccount = $connection->fetchOne(
            'SELECT user_account_id FROM oauth_identity WHERE provider_user_id = :sub',
            ['sub' => "g-keep-{$suffix}"],
        );
        self::assertSame($existing->id->toString(), $identityAccount);

        $duplicates = self::countRows($connection, 'SELECT COUNT(*) FROM user_account WHERE email = :email', ['email' => $providerEmail]);
        self::assertSame(0, $duplicates, 'No second account may appear');
    }

    /**
     * Without the browser binding the finish URL would be a forged-connect
     * primitive: park your own Google profile, send the link to a signed-in
     * victim, sign in to their account with Google afterwards.
     */
    public function testInterstitialFinishUrlOpenedInAnotherBrowserLinksNothing(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $victim = $this->seedAccount($browser, "victim2+{$suffix}@example.com", password: 'hash');

        $returnPath = $this->parkThroughInterstitial($browser, "g-steal-{$suffix}", "attacker2+{$suffix}@gmail.com");

        // The victim's browser: signed in, but never saw the binding cookie
        $browser->getCookieJar()->clear();
        $browser->loginUser($victim, 'main');
        $browser->request('GET', $returnPath);

        self::assertResponseRedirects();
        self::assertStringContainsString('social_link_result=failed', (string) $browser->getResponse()->headers->get('Location'));
        self::assertSame(0, $this->identityCount($browser, "g-steal-{$suffix}"));
    }

    public function testInterstitialLinksToTheFaqAboutDuplicateAccounts(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $state = $this->startFlow($browser, 'google', 'accounts.google.com');

        $this->queueGoogleExchange("g-faq-{$suffix}", "faq+{$suffix}@example.com", emailVerified: true);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        $crawler = $browser->request('GET', (string) $browser->getResponse()->headers->get('Location'));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href$="#duplicate-accounts"]'));
    }

    public function testOneAccountCanSignInWithAllThreeProviders(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google, OauthProvider::Facebook, OauthProvider::Apple);
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $userAccount = $this->seedAccount($browser, "three+{$suffix}@example.com", password: 'hash');
        $browser->loginUser($userAccount, 'main');

        // Connect all three from settings, each under a different address
        $browser->request('GET', '/account/social/google/connect');
        $state = $this->stateFromLocation($browser, 'accounts.google.com');
        $this->queueGoogleExchange("g-three-{$suffix}", "three+{$suffix}@gmail.com", emailVerified: true);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");
        $this->assertFinishConnects($browser);

        $browser->request('GET', '/account/social/facebook/connect');
        $state = $this->stateFromLocation($browser, 'facebook.com');
        $this->queueFacebookExchange("fb-three-{$suffix}", "three+{$suffix}@facebook.example.com");
        $browser->request('GET', "/login/social/facebook/callback?state={$state}&code=fake-code");
        $this->assertFinishConnects($browser);

        $browser->request('GET', '/account/social/apple/connect');
        $state = $this->stateFromLocation($browser, 'appleid.apple.com');
        AppleIdTokenFactory::queueTokenExchange([
            'sub' => "apple-three-{$suffix}",
            'email' => "three{$suffix}@privaterelay.appleid.com",
            'email_verified' => 'true',
            'is_private_email' => 'true',
        ]);
        $browser->request('POST', '/login/social/apple/callback', ['state' => $state, 'code' => 'fake-apple-code']);
        $this->assertFinishConnects($browser);

        $connection = $browser->getContainer()->get(Connection::class);
        self::assertSame(3, self::countRows(
            $connection,
            'SELECT COUNT(*) FROM oauth_identity WHERE user_account_id = :id',
            ['id' => $userAccount->id->toString()],
        ));

        // ...and every one of them signs in to that same account (rule 1)
        $browser->request('GET', '/logout');
        $state = $this->startFlow($browser, 'google', 'accounts.google.com');
        $this->queueGoogleExchange("g-three-{$suffix}", "three+{$suffix}@gmail.com", emailVerified: true);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");
        $this->assertSignedInAs($browser, $userAccount);

        $browser->request('GET', '/logout');
        $state = $this->startFlow($browser, 'facebook', 'facebook.com');
        $this->queueFacebookExchange("fb-three-{$suffix}", "three+{$suffix}@facebook.example.com");
        $browser->request('GET', "/login/social/facebook/callback?state={$state}&code=fake-code");
        $this->assertSignedInAs($browser, $userAccount);

        $browser->request('GET', '/logout');
        $state = $this->startFlow($browser, 'apple', 'appleid.apple.com');
        AppleIdTokenFactory::queueTokenExchange([
            'sub' => "apple-three-{$suffix}",
            'email' => "three{$suffix}@privaterelay.appleid.com",
            'email_verified' => true,
        ]);
        $browser->request('POST', '/login/social/apple/callback', ['state' => $state, 'code' => 'fake-apple-code']);
        $this->assertSignedInAs($browser, $userAccount);
    }

    public function testCancelAtGoogleShowsFriendlyMessage(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');
        $userAgent = 'cancel-google-' . bin2hex(random_bytes(4));
        $browser->request('GET', "/login/social/google/callback?state={$state}&error=access_denied", server: ['HTTP_USER_AGENT' => $userAgent]);

        self::assertSame('You cancelled signing in with Google. Nothing was changed.', $this->followToLoginError($browser));
        $this->assertFailureAudited($browser, $userAgent, 'provider_cancelled');
        $this->assertNotLoggedIn($browser);
    }

    public function testCancelAtAppleFormPostShowsFriendlyMessage(): void
    {
        $this->enableApplePublicly();
        $browser = self::createClient();

        $state = $this->startFlow($browser, 'apple', 'appleid.apple.com');
        $userAgent = 'cancel-apple-' . bin2hex(random_bytes(4));
        $browser->request('POST', '/login/social/apple/callback', [
            'state' => $state,
            'error' => 'user_cancelled_authorize',
        ], server: ['HTTP_USER_AGENT' => $userAgent]);

        self::assertSame('You cancelled signing in with Apple. Nothing was changed.', $this->followToLoginError($browser));
        $this->assertFailureAudited($browser, $userAgent, 'provider_cancelled');
    }

    public function testOtherProviderErrorShowsTryAgainMessage(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');
        $userAgent = 'provider-error-' . bin2hex(random_bytes(4));
        $browser->request('GET', "/login/social/google/callback?state={$state}&error=server_error", server: ['HTTP_USER_AGENT' => $userAgent]);

        self::assertSame(
            "Signing in with Google didn't work. Please try again, or sign in with your e-mail.",
            $this->followToLoginError($browser),
        );
        $this->assertFailureAudited($browser, $userAgent, 'provider_error');
    }

    public function testMissingCodeShowsTryAgainMessage(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');
        $userAgent = 'code-missing-' . bin2hex(random_bytes(4));
        $browser->request('GET', "/login/social/google/callback?state={$state}", server: ['HTTP_USER_AGENT' => $userAgent]);

        self::assertSame(
            "Signing in with Google didn't work. Please try again, or sign in with your e-mail.",
            $this->followToLoginError($browser),
        );
        $this->assertFailureAudited($browser, $userAgent, 'code_missing');
    }

    public function testFailedCodeExchangeShowsTryAgainMessage(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');
        SocialLoginHttpMock::queue(new HttpResponse(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}'));
        $userAgent = 'exchange-failed-' . bin2hex(random_bytes(4));
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code", server: ['HTTP_USER_AGENT' => $userAgent]);

        self::assertSame(
            "Signing in with Google didn't work. Please try again, or sign in with your e-mail.",
            $this->followToLoginError($browser),
        );
        $this->assertFailureAudited($browser, $userAgent, 'code_exchange_failed');
    }

    public function testStateOfAnotherProviderShowsTookTooLongMessage(): void
    {
        // A login-intent state minted for Google, replayed at Apple's callback:
        // supports() only peeks at the intent, authenticate() rejects the mismatch
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $this->enableApplePublicly();
        $browser = self::createClient();

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');
        $userAgent = 'state-invalid-' . bin2hex(random_bytes(4));
        $browser->request('POST', '/login/social/apple/callback', [
            'state' => $state,
            'code' => 'fake-apple-code',
        ], server: ['HTTP_USER_AGENT' => $userAgent]);

        self::assertSame(
            'Signing in with Apple took too long or was opened twice. Please try again.',
            $this->followToLoginError($browser),
        );
        $this->assertFailureAudited($browser, $userAgent, 'state_invalid');
    }

    public function testRefusedAutoLinkShowsTheSameGenericMessage(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $email = "autolink-refused+{$suffix}@example.com";
        $userAccount = $this->seedAccount($browser, $email, password: 'hash');
        // The account already carries ANOTHER Google identity - rule 2 must not add a second
        $this->seedIdentity($browser, $userAccount, OauthProvider::Google, "g-existing-{$suffix}");

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');
        $this->queueGoogleExchange("g-second-{$suffix}", $email, emailVerified: true);
        $userAgent = 'autolink-refused-' . $suffix;
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code", server: ['HTTP_USER_AGENT' => $userAgent]);

        self::assertSame(self::NOT_POSSIBLE_GOOGLE, $this->followToLoginError($browser));
        $this->assertFailureAudited($browser, $userAgent, 'auto_link_refused');
        self::assertSame(0, $this->identityCount($browser, "g-second-{$suffix}"));
        $this->assertNotLoggedIn($browser);
    }

    public function testRule3RefusalRecordsItsReasonCode(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $email = "rule3-code+{$suffix}@example.com";
        $this->seedAccount($browser, $email, password: 'hash');

        $state = $this->startFlow($browser, 'google', 'accounts.google.com');
        $this->queueGoogleExchange("g-rule3-code-{$suffix}", $email, emailVerified: false);
        $userAgent = 'rule3-code-' . $suffix;
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code", server: ['HTTP_USER_AGENT' => $userAgent]);

        self::assertStringContainsString('Google has not confirmed that the address belongs to you.', $this->followToLoginError($browser));
        $this->assertFailureAudited($browser, $userAgent, 'provider_email_unverified');
    }

    public function testEveryFailureMessageIsTranslatedInAllLocales(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);

        foreach (['cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            $catalogue = $translator->getCatalogue($locale);

            foreach (SocialLoginFailureReason::cases() as $reason) {
                self::assertTrue(
                    $catalogue->defines($reason->messageKey(), 'security'),
                    "Missing {$locale} translation for {$reason->value}",
                );
            }
        }

        self::assertSame(
            'Přihlášení přes Apple jste zrušili. Nic se nezměnilo.',
            $translator->trans(SocialLoginFailureReason::MESSAGE_CANCELLED, ['%provider%' => 'Apple'], 'security', 'cs'),
        );
    }

    public function testOneIdentityPerProviderPerAccountIsEnforcedByTheDatabase(): void
    {
        $browser = self::createClient();

        $suffix = bin2hex(random_bytes(4));
        $userAccount = $this->seedAccount($browser, "uniq+{$suffix}@example.com", password: 'hash');
        $this->seedIdentity($browser, $userAccount, OauthProvider::Google, "g-uniq-a-{$suffix}");

        $this->expectException(UniqueConstraintViolationException::class);

        // A second Google identity on the same account - what two racing link
        // flows would produce past the handler's advisory check
        $this->seedIdentity($browser, $userAccount, OauthProvider::Google, "g-uniq-b-{$suffix}");
    }

    /**
     * Follows the failure redirect and returns the login page's error text -
     * never Symfony's raw "An authentication exception occurred.".
     */
    private function followToLoginError(KernelBrowser $browser): string
    {
        self::assertResponseRedirects('/login');

        $crawler = $browser->followRedirect();
        $alert = $crawler->filter('.alert-danger');
        self::assertCount(1, $alert);

        $text = $alert->text();
        self::assertStringNotContainsString('An authentication exception occurred', $text);

        return $text;
    }

    private function assertFailureAudited(KernelBrowser $browser, string $userAgent, string $expectedCode): void
    {
        $metadata = $browser->getContainer()->get(Connection::class)->fetchOne(
            "SELECT metadata FROM auth_audit_log WHERE event_type = 'login_failure' AND user_agent = :ua",
            ['ua' => $userAgent],
        );
        self::assertIsString($metadata, 'The failure must land in the audit log');

        /** @var array{reason?: string, code?: string} $decoded */
        $decoded = json_decode($metadata, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($expectedCode, $decoded['code'] ?? null);
        self::assertSame(SocialLoginFailed::class, $decoded['reason'] ?? null);
    }

    private function enableApplePublicly(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Apple);
    }

    /**
     * Drives a rule-4 flow to the interstitial and answers "I already have an
     * account".
     *
     * @return string the post-login ?return= path (the link finish route)
     */
    private function parkThroughInterstitial(KernelBrowser $browser, string $sub, string $providerEmail): string
    {
        $state = $this->startFlow($browser, 'google', 'accounts.google.com');

        $this->queueGoogleExchange($sub, $providerEmail, emailVerified: true);
        $browser->request('GET', "/login/social/google/callback?state={$state}&code=fake-code");

        $browser->request('POST', '/register/social/sign-in', ['token' => $this->registrationTokenFromLocation($browser)]);

        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/login?return=', $location);
        self::assertNotNull($browser->getCookieJar()->get('msp_social_link', '/connect/social'), 'The binding cookie must be set');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $returnPath = $query['return'] ?? null;
        self::assertIsString($returnPath);
        self::assertStringStartsWith('/connect/social/google/finish/', $returnPath);

        // The login page carries the destination on
        $browser->request('GET', $location);
        self::assertResponseIsSuccessful();

        return $returnPath;
    }

    private function registrationTokenFromLocation(KernelBrowser $browser): string
    {
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringContainsString('/register/social?token=', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    private function assertFinishConnects(KernelBrowser $browser): void
    {
        self::assertResponseStatusCodeSame(303);
        $browser->followRedirect();
        self::assertStringContainsString('social_link_result=connected', (string) $browser->getResponse()->headers->get('Location'));
    }

    private function assertSignedInAs(KernelBrowser $browser, UserAccount $userAccount): void
    {
        self::assertResponseRedirects();
        self::assertStringContainsString('my-profile', (string) $browser->getResponse()->headers->get('Location'));

        $token = $browser->getContainer()->get(TokenStorageInterface::class)->getToken();
        self::assertNotNull($token);
        self::assertSame($userAccount->userId, $token->getUserIdentifier());
    }

    private function assertLinkNoticeQueued(KernelBrowser $browser, UserAccount $userAccount, OauthProvider $provider): void
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
        self::assertSame($provider, $notices[0]->provider);
    }

    private function assertRememberMeCookieIssued(KernelBrowser $browser): void
    {
        foreach ($browser->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'REMEMBERME' && (string) $cookie->getValue() !== '') {
                return;
            }
        }

        self::fail('A new social account must get the always-on remember-me cookie');
    }

    private function identityCount(KernelBrowser $browser, string $providerUserId): int
    {
        return self::countRows(
            $browser->getContainer()->get(Connection::class),
            'SELECT COUNT(*) FROM oauth_identity WHERE provider_user_id = :sub',
            ['sub' => $providerUserId],
        );
    }

    private function queueFacebookExchange(string $id, string $email): void
    {
        SocialLoginHttpMock::queue(
            self::jsonResponse(['access_token' => 'fb-token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            self::jsonResponse(['id' => $id, 'name' => 'Facebook User', 'email' => $email]),
        );
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

    /**
     * Seeded accounts are verified by default, like every account that existed
     * when the 2026-09-29 backfill ran; pass false for a fresh, unconfirmed one.
     */
    private function seedAccount(KernelBrowser $browser, string $email, null|string $password, bool $emailVerified = true): UserAccount
    {
        $userId = 'msp|' . Uuid::uuid7()->toString();

        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());

        if ($emailVerified) {
            $userAccount->markEmailVerified(new DateTimeImmutable());
        }

        if ($password !== null) {
            $userAccount->changePassword($password);
        }

        $player = new Player(Uuid::uuid7(), 'SL' . bin2hex(random_bytes(3)), $userId, null, new DateTimeImmutable());

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
