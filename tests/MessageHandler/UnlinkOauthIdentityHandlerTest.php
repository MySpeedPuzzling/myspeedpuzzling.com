<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OauthIdentity;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\CannotUnlinkLastSignInMethod;
use SpeedPuzzling\Web\Exceptions\OauthIdentityNotFound;
use SpeedPuzzling\Web\Message\UnlinkOauthIdentity;
use SpeedPuzzling\Web\Repository\OauthIdentityRepository;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class UnlinkOauthIdentityHandlerTest extends KernelTestCase
{
    use OverridesFeatureFlagEnv;

    private MessageBusInterface $messageBus;
    private OauthIdentityRepository $oauthIdentityRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_ADMIN_ONLY', false);

        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->oauthIdentityRepository = $container->get(OauthIdentityRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testUnlinksWhenAccountHasPassword(): void
    {
        $userAccount = $this->createUserAccount('msp|unlink1', 'unlink.one@example.com', password: 'hash');
        $this->createOauthIdentity($userAccount, OauthProvider::Google, 'google-unlink-1');

        $this->messageBus->dispatch(new UnlinkOauthIdentity('msp|unlink1', OauthProvider::Google));

        self::assertNull($this->oauthIdentityRepository->findByProviderUserId(OauthProvider::Google, 'google-unlink-1'));

        /** @var false|array{authenticator: null|string} $auditRow */
        $auditRow = $this->entityManager->getConnection()->fetchAssociative(
            "SELECT authenticator FROM auth_audit_log WHERE event_type = 'oauth_identity_unlinked' AND user_account_id = :id",
            ['id' => $userAccount->id->toString()],
        );
        self::assertNotFalse($auditRow);
        self::assertSame('social:google', $auditRow['authenticator']);
    }

    public function testLastSignInMethodOfPasswordlessAccountCannotBeUnlinked(): void
    {
        $userAccount = $this->createUserAccount('msp|unlink2', 'unlink.two@example.com', password: null);
        $this->createOauthIdentity($userAccount, OauthProvider::Google, 'google-unlink-2');

        try {
            $this->messageBus->dispatch(new UnlinkOauthIdentity('msp|unlink2', OauthProvider::Google));
            self::fail('Expected CannotUnlinkLastSignInMethod was not thrown');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(CannotUnlinkLastSignInMethod::class, $e->getPrevious());
        }

        self::assertNotNull(
            $this->oauthIdentityRepository->findByProviderUserId(OauthProvider::Google, 'google-unlink-2'),
            'The invariant must keep the identity in place',
        );
    }

    public function testPasswordlessAccountWithTwoIdentitiesCanUnlinkOne(): void
    {
        $userAccount = $this->createUserAccount('msp|unlink3', 'unlink.three@example.com', password: null);
        $this->createOauthIdentity($userAccount, OauthProvider::Google, 'google-unlink-3');
        $this->createOauthIdentity($userAccount, OauthProvider::Facebook, 'fb-unlink-3');

        $this->messageBus->dispatch(new UnlinkOauthIdentity('msp|unlink3', OauthProvider::Google));

        self::assertNull($this->oauthIdentityRepository->findByProviderUserId(OauthProvider::Google, 'google-unlink-3'));
        self::assertNotNull($this->oauthIdentityRepository->findByProviderUserId(OauthProvider::Facebook, 'fb-unlink-3'));
    }

    public function testUnlinkingAProviderThatIsNotLinkedFails(): void
    {
        $this->createUserAccount('msp|unlink4', 'unlink.four@example.com', password: 'hash');

        // OauthIdentityNotFound extends NotFoundHttpException, so it arrives
        // unwrapped (UnwrapHttpExceptionMiddleware).
        $this->expectException(OauthIdentityNotFound::class);

        $this->messageBus->dispatch(new UnlinkOauthIdentity('msp|unlink4', OauthProvider::Apple));
    }

    private function createUserAccount(string $userId, string $email, null|string $password): UserAccount
    {
        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());

        if ($password !== null) {
            $userAccount->changePassword($password);
        }

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
