<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OauthIdentity;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Services\SocialLogin\AppleServerNotificationVerifier;
use SpeedPuzzling\Web\Tests\TestDouble\AppleIdTokenFactory;
use SpeedPuzzling\Web\Tests\TestDouble\SocialLoginHttpMock;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sign in with Apple server-to-server notifications, end to end: real RS256
 * event tokens verified against a mocked JWKS, then the identity rules of
 * ProcessAppleSignInEventHandler.
 */
final class AppleSignInNotificationControllerTest extends WebTestCase
{
    private const string APP_ID = 'com.myspeedpuzzling.app';

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        SocialLoginHttpMock::reset();
        $this->overrideEnv('APPLE_CLIENT_ID', AppleIdTokenFactory::CLIENT_ID);
        $this->overrideEnv('APPLE_APP_ID', self::APP_ID);

        $this->browser = self::createClient();

        // Every test signs with a fresh key under the same kid - start without cached keys
        $this->browser->getContainer()->get('social_login_state_cache')
            ->deleteItem(AppleServerNotificationVerifier::JWKS_CACHE_KEY);
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

        $this->originalEnv = [];

        parent::tearDown();
    }

    public function testConsentRevokedRemovesTheIdentityOfAnAccountWithAPassword(): void
    {
        $sub = 'apple-revoke-' . bin2hex(random_bytes(4));
        $userAccount = $this->seedAccount(password: 'hash');
        $this->seedIdentity($userAccount, $sub);

        $this->postNotification(['type' => 'consent-revoked', 'sub' => $sub, 'event_time' => time() * 1000]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->identityCount($sub));

        $auditMetadata = $this->connection()->fetchOne(
            "SELECT metadata FROM auth_audit_log WHERE event_type = 'oauth_identity_unlinked' AND user_account_id = :id",
            ['id' => $userAccount->id->toString()],
        );
        self::assertIsString($auditMetadata);
        self::assertStringContainsString('apple_server_notification', $auditMetadata);
    }

    public function testConsentRevokedKeepsTheOnlySignInMethod(): void
    {
        $sub = 'apple-revoke-last-' . bin2hex(random_bytes(4));
        $this->seedIdentity($this->seedAccount(password: null), $sub);

        $this->postNotification(['type' => 'consent-revoked', 'sub' => $sub, 'event_time' => time() * 1000]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->identityCount($sub), 'Re-consenting gives back the same sub - rule 1 must still find the account');
    }

    /**
     * Apple's docs say "account-delete", real notifications "account-deleted";
     * `events` arrives as an object here (the documented shape).
     */
    public function testAccountDeleteRemovesEvenTheOnlySignInMethod(): void
    {
        $sub = 'apple-delete-' . bin2hex(random_bytes(4));
        $this->seedIdentity($this->seedAccount(password: null), $sub);

        $this->postNotification(['type' => 'account-delete', 'sub' => $sub, 'event_time' => time() * 1000], eventsAsString: false);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->identityCount($sub));
    }

    public function testAccountDeletedIsIdempotent(): void
    {
        $sub = 'apple-deleted-' . bin2hex(random_bytes(4));
        $this->seedIdentity($this->seedAccount(password: 'hash'), $sub);

        $token = $this->postNotification(['type' => 'account-deleted', 'sub' => $sub, 'event_time' => time() * 1000]);
        self::assertResponseStatusCodeSame(200);

        // Apple may deliver twice - the keys are cached now (nothing queued for a second fetch)
        $this->postToken($token);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->identityCount($sub));
    }

    public function testEmailForwardingChangesKeepTheIdentity(): void
    {
        $sub = 'apple-email-' . bin2hex(random_bytes(4));
        $this->seedIdentity($this->seedAccount(password: 'hash'), $sub);

        $this->postNotification([
            'type' => 'email-disabled',
            'sub' => $sub,
            'email' => 'abc@privaterelay.appleid.com',
            'is_private_email' => 'true',
            'event_time' => time() * 1000,
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->identityCount($sub));
    }

    public function testTheServicesIdIsAcceptedAsAudienceToo(): void
    {
        $sub = 'apple-aud-services-' . bin2hex(random_bytes(4));
        $this->seedIdentity($this->seedAccount(password: 'hash'), $sub);

        $this->postNotification(['type' => 'consent-revoked', 'sub' => $sub], audience: AppleIdTokenFactory::CLIENT_ID);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->identityCount($sub));
    }

    public function testUnknownEventTypeIsAcknowledged(): void
    {
        $this->postNotification(['type' => 'something-new', 'sub' => 'apple-unknown']);

        self::assertResponseStatusCodeSame(200);
    }

    public function testTokenForAnotherAppIsRejected(): void
    {
        $sub = 'apple-other-app-' . bin2hex(random_bytes(4));
        $this->seedIdentity($this->seedAccount(password: 'hash'), $sub);

        $this->postNotification(['type' => 'consent-revoked', 'sub' => $sub], audience: 'com.somebody.else', queueJwks: false);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(1, $this->identityCount($sub));
    }

    public function testTokenSignedByAnotherKeyIsRejected(): void
    {
        $sub = 'apple-forged-' . bin2hex(random_bytes(4));
        $this->seedIdentity($this->seedAccount(password: 'hash'), $sub);

        [, $forgedToken] = AppleIdTokenFactory::signedIdToken(self::claims(['type' => 'consent-revoked', 'sub' => $sub], self::APP_ID, true));
        [$appleJwks] = AppleIdTokenFactory::signedIdToken([]);
        SocialLoginHttpMock::queue(new HttpResponse(200, ['Content-Type' => 'application/json'], $appleJwks));

        $this->postToken($forgedToken);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(1, $this->identityCount($sub));
    }

    public function testGarbageIsRejected(): void
    {
        $this->browser->request('POST', '/webhook/apple-sign-in', server: ['CONTENT_TYPE' => 'application/json'], content: '{"payload": "not.a.jwt"}');
        self::assertResponseStatusCodeSame(400);

        $this->browser->request('POST', '/webhook/apple-sign-in', content: 'hello');
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function postNotification(array $event, string $audience = self::APP_ID, bool $eventsAsString = true, bool $queueJwks = true): string
    {
        [$jwks, $token] = AppleIdTokenFactory::signedIdToken(self::claims($event, $audience, $eventsAsString));

        if ($queueJwks) {
            SocialLoginHttpMock::queue(new HttpResponse(200, ['Content-Type' => 'application/json'], $jwks));
        }

        $this->postToken($token);

        return $token;
    }

    private function postToken(string $token): void
    {
        $this->browser->request(
            'POST',
            '/webhook/apple-sign-in',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['payload' => $token], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private static function claims(array $event, string $audience, bool $eventsAsString): array
    {
        return [
            'iss' => 'https://appleid.apple.com',
            'aud' => $audience,
            'iat' => time(),
            'exp' => time() + 600,
            'jti' => bin2hex(random_bytes(8)),
            'events' => $eventsAsString ? json_encode($event, JSON_THROW_ON_ERROR) : $event,
        ];
    }

    private function seedAccount(null|string $password): UserAccount
    {
        $userAccount = new UserAccount(
            Uuid::uuid7(),
            'msp|' . Uuid::uuid7()->toString(),
            'apple-s2s+' . bin2hex(random_bytes(4)) . '@example.com',
            new DateTimeImmutable(),
        );

        if ($password !== null) {
            $userAccount->changePassword($password);
        }

        $entityManager = $this->browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->flush();

        return $userAccount;
    }

    private function seedIdentity(UserAccount $userAccount, string $sub): void
    {
        $entityManager = $this->browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new OauthIdentity(
            id: Uuid::uuid7(),
            userAccount: $userAccount,
            provider: OauthProvider::Apple,
            providerUserId: $sub,
            emailAtLink: $userAccount->email,
            linkedAt: new DateTimeImmutable(),
        ));
        $entityManager->flush();
    }

    private function identityCount(string $sub): int
    {
        $count = $this->connection()->fetchOne(
            "SELECT COUNT(*) FROM oauth_identity WHERE provider = 'apple' AND provider_user_id = :sub",
            ['sub' => $sub],
        );
        assert(is_int($count) || is_string($count));

        return (int) $count;
    }

    private function connection(): Connection
    {
        return $this->browser->getContainer()->get(Connection::class);
    }

    private function overrideEnv(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->originalEnv)) {
            $original = $_ENV[$name] ?? false;
            $this->originalEnv[$name] = is_string($original) ? $original : false;
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
