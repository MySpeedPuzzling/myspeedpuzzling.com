<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OauthIdentity;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\EmailAlreadyRegistered;
use SpeedPuzzling\Web\Exceptions\OauthIdentityAlreadyLinked;
use SpeedPuzzling\Web\Exceptions\SocialLoginRestrictedToAdmins;
use SpeedPuzzling\Web\Message\RegisterWithOauthIdentity;
use SpeedPuzzling\Web\Repository\OauthIdentityRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class RegisterWithOauthIdentityHandlerTest extends KernelTestCase
{
    use OverridesFeatureFlagEnv;

    private MessageBusInterface $messageBus;
    private UserAccountRepository $userAccountRepository;
    private PlayerRepository $playerRepository;
    private OauthIdentityRepository $oauthIdentityRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        // The repo baseline ships admin-only ON; these tests cover the public
        // stage, the admin-only refusal overrides back explicitly
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_ADMIN_ONLY', false);

        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->userAccountRepository = $container->get(UserAccountRepository::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
        $this->oauthIdentityRepository = $container->get(OauthIdentityRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testRegistrationCreatesAccountPlayerAndIdentityTogether(): void
    {
        $userId = $this->register(new RegisterWithOauthIdentity(
            provider: OauthProvider::Google,
            providerUserId: 'google-reg-1',
            email: ' Social.One@EXAMPLE.com ',
            emailVerified: true,
            name: 'Social One',
            locale: 'cs',
        ));

        self::assertStringStartsWith('msp|', $userId);

        $userAccount = $this->userAccountRepository->findByUserId($userId);
        self::assertNotNull($userAccount);
        self::assertSame('social.one@example.com', $userAccount->email);
        // Social-only account: no password, provider-verified email
        self::assertNull($userAccount->password);
        self::assertNotNull($userAccount->emailVerifiedAt);
        self::assertFalse($userAccount->legacyAuth0);

        $player = $this->playerRepository->findByUserId($userId);
        self::assertNotNull($player);
        self::assertSame('social.one@example.com', $player->email);
        self::assertSame('Social One', $player->name);
        self::assertSame('cs', $player->locale);

        $oauthIdentity = $this->oauthIdentityRepository->findByProviderUserId(OauthProvider::Google, 'google-reg-1');
        self::assertNotNull($oauthIdentity);
        self::assertSame($userAccount->id->toString(), $oauthIdentity->userAccount->id->toString());
        self::assertSame('social.one@example.com', $oauthIdentity->emailAtLink);
        self::assertNotNull($oauthIdentity->lastUsedAt, 'Registering through the provider is the first use');

        // The audit row must reference the account created in the same
        // transaction (identity-map resolution, like native registration)
        /** @var false|array{user_account_id: null|string, authenticator: null|string} $auditRow */
        $auditRow = $this->entityManager->getConnection()->fetchAssociative(
            "SELECT user_account_id, authenticator FROM auth_audit_log WHERE event_type = 'oauth_registration' AND email = :email",
            ['email' => 'social.one@example.com'],
        );
        self::assertNotFalse($auditRow);
        self::assertSame($userAccount->id->toString(), $auditRow['user_account_id']);
        self::assertSame('social:google', $auditRow['authenticator']);
    }

    public function testUnverifiedProviderEmailLeavesAccountUnverified(): void
    {
        $userId = $this->register(new RegisterWithOauthIdentity(
            provider: OauthProvider::Facebook,
            providerUserId: 'fb-reg-2',
            email: 'social.two@example.com',
            emailVerified: false,
            name: null,
            locale: null,
        ));

        $userAccount = $this->userAccountRepository->findByUserId($userId);
        self::assertNotNull($userAccount);
        self::assertNull($userAccount->emailVerifiedAt);
    }

    public function testExistingEmailIsRejected(): void
    {
        $this->createUserAccount('msp|social3', 'social.three@example.com');

        try {
            $this->register(new RegisterWithOauthIdentity(
                provider: OauthProvider::Google,
                providerUserId: 'google-reg-3',
                email: 'Social.THREE@example.com',
                emailVerified: true,
                name: null,
                locale: null,
            ));
            self::fail('Expected EmailAlreadyRegistered was not thrown');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(EmailAlreadyRegistered::class, $e->getPrevious());
        }
    }

    public function testAlreadyClaimedIdentityIsRejected(): void
    {
        $existingAccount = $this->createUserAccount('msp|social4', 'social.four@example.com');
        $this->createOauthIdentity($existingAccount, OauthProvider::Google, 'google-reg-4');

        try {
            $this->register(new RegisterWithOauthIdentity(
                provider: OauthProvider::Google,
                providerUserId: 'google-reg-4',
                email: 'social.four.other@example.com',
                emailVerified: true,
                name: null,
                locale: null,
            ));
            self::fail('Expected OauthIdentityAlreadyLinked was not thrown');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(OauthIdentityAlreadyLinked::class, $e->getPrevious());
        }
    }

    public function testRegistrationIsRefusedEntirelyDuringAdminOnlyStage(): void
    {
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_ADMIN_ONLY', true);

        try {
            $this->register(new RegisterWithOauthIdentity(
                provider: OauthProvider::Google,
                providerUserId: 'google-reg-5',
                email: 'social.five@example.com',
                emailVerified: true,
                name: null,
                locale: null,
            ));
            self::fail('Expected SocialLoginRestrictedToAdmins was not thrown');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(SocialLoginRestrictedToAdmins::class, $e->getPrevious());
        }

        self::assertNull($this->userAccountRepository->findByEmail('social.five@example.com'));
    }

    private function register(RegisterWithOauthIdentity $message): string
    {
        $envelope = $this->messageBus->dispatch($message);

        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        $userId = $handledStamp->getResult();
        self::assertIsString($userId);

        return $userId;
    }

    private function createUserAccount(string $userId, string $email): UserAccount
    {
        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());

        $this->entityManager->persist($userAccount);
        $this->entityManager->flush();

        return $userAccount;
    }

    private function createOauthIdentity(UserAccount $userAccount, OauthProvider $provider, string $providerUserId): void
    {
        $this->entityManager->persist(new OauthIdentity(
            id: Uuid::uuid7(),
            userAccount: $userAccount,
            provider: $provider,
            providerUserId: $providerUserId,
            emailAtLink: $userAccount->email,
            linkedAt: new DateTimeImmutable(),
        ));
        $this->entityManager->flush();
    }
}
