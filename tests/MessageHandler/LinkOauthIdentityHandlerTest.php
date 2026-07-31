<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OauthIdentity;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\OauthIdentityAlreadyLinked;
use SpeedPuzzling\Web\Exceptions\SocialLoginRestrictedToAdmins;
use SpeedPuzzling\Web\Exceptions\UserAccountNotFound;
use SpeedPuzzling\Web\Message\LinkOauthIdentity;
use SpeedPuzzling\Web\Repository\OauthIdentityRepository;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class LinkOauthIdentityHandlerTest extends KernelTestCase
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

    public function testLinksIdentityWithoutAnyEmailMatchRequirement(): void
    {
        $userAccount = $this->createUserAccount('msp|link1', 'link.one@example.com');

        // Rule 5: the provider email may be completely different - ownership is
        // proven by the authenticated session, not the address
        $this->messageBus->dispatch(new LinkOauthIdentity(
            userId: 'msp|link1',
            provider: OauthProvider::Google,
            providerUserId: 'google-link-1',
            emailAtLink: 'other.address@gmail.com',
        ));

        $oauthIdentity = $this->oauthIdentityRepository->findByProviderUserId(OauthProvider::Google, 'google-link-1');
        self::assertNotNull($oauthIdentity);
        self::assertSame($userAccount->id->toString(), $oauthIdentity->userAccount->id->toString());
        self::assertSame('other.address@gmail.com', $oauthIdentity->emailAtLink);
        self::assertNull($oauthIdentity->lastUsedAt, 'A settings link is not a sign-in');

        /** @var false|array{authenticator: null|string} $auditRow */
        $auditRow = $this->entityManager->getConnection()->fetchAssociative(
            "SELECT authenticator FROM auth_audit_log WHERE event_type = 'oauth_identity_linked' AND user_account_id = :id",
            ['id' => $userAccount->id->toString()],
        );
        self::assertNotFalse($auditRow);
        self::assertSame('social:google', $auditRow['authenticator']);
    }

    public function testLoginAutoLinkStampsLastUsed(): void
    {
        $this->createUserAccount('msp|link2', 'link.two@example.com');

        $this->messageBus->dispatch(new LinkOauthIdentity(
            userId: 'msp|link2',
            provider: OauthProvider::Apple,
            providerUserId: 'apple-link-2',
            emailAtLink: 'relay@privaterelay.appleid.com',
            usedForLogin: true,
        ));

        $oauthIdentity = $this->oauthIdentityRepository->findByProviderUserId(OauthProvider::Apple, 'apple-link-2');
        self::assertNotNull($oauthIdentity);
        self::assertNotNull($oauthIdentity->lastUsedAt);
    }

    public function testIdentityClaimedByAnotherAccountIsRejected(): void
    {
        $firstAccount = $this->createUserAccount('msp|link3', 'link.three@example.com');
        $this->createOauthIdentity($firstAccount, OauthProvider::Google, 'google-link-3');
        $this->createUserAccount('msp|link4', 'link.four@example.com');

        $this->expectLinkRejected(new LinkOauthIdentity(
            userId: 'msp|link4',
            provider: OauthProvider::Google,
            providerUserId: 'google-link-3',
            emailAtLink: null,
        ), OauthIdentityAlreadyLinked::class);
    }

    public function testSecondIdentityOfSameProviderIsRejected(): void
    {
        $userAccount = $this->createUserAccount('msp|link5', 'link.five@example.com');
        $this->createOauthIdentity($userAccount, OauthProvider::Google, 'google-link-5');

        $this->expectLinkRejected(new LinkOauthIdentity(
            userId: 'msp|link5',
            provider: OauthProvider::Google,
            providerUserId: 'google-link-5-other',
            emailAtLink: null,
        ), OauthIdentityAlreadyLinked::class);
    }

    public function testUnknownAccountIsRejected(): void
    {
        $this->expectLinkRejected(new LinkOauthIdentity(
            userId: 'msp|link-nobody',
            provider: OauthProvider::Google,
            providerUserId: 'google-link-6',
            emailAtLink: null,
        ), UserAccountNotFound::class);
    }

    public function testAdminOnlyStageRefusesNonAdmins(): void
    {
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_ADMIN_ONLY', true);

        $this->createUserAccount('msp|link7', 'link.seven@example.com');
        $this->createPlayer('msp|link7', 'lnk7', isAdmin: false);

        $this->expectLinkRejected(new LinkOauthIdentity(
            userId: 'msp|link7',
            provider: OauthProvider::Google,
            providerUserId: 'google-link-7',
            emailAtLink: null,
        ), SocialLoginRestrictedToAdmins::class);
    }

    public function testAdminOnlyStageAllowsAdmins(): void
    {
        $this->overrideFeatureFlagEnv('SOCIAL_LOGIN_ADMIN_ONLY', true);

        $this->createUserAccount('msp|link8', 'link.eight@example.com');
        $this->createPlayer('msp|link8', 'lnk8', isAdmin: true);

        $this->messageBus->dispatch(new LinkOauthIdentity(
            userId: 'msp|link8',
            provider: OauthProvider::Google,
            providerUserId: 'google-link-8',
            emailAtLink: null,
        ));

        self::assertNotNull($this->oauthIdentityRepository->findByProviderUserId(OauthProvider::Google, 'google-link-8'));
    }

    /**
     * @param class-string<\Throwable> $expectedException
     */
    private function expectLinkRejected(LinkOauthIdentity $message, string $expectedException): void
    {
        try {
            $this->messageBus->dispatch($message);
            self::fail(sprintf('Expected %s was not thrown', $expectedException));
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf($expectedException, $e->getPrevious());
        }
    }

    private function createUserAccount(string $userId, string $email): UserAccount
    {
        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());

        $this->entityManager->persist($userAccount);
        $this->entityManager->flush();

        return $userAccount;
    }

    private function createPlayer(string $userId, string $code, bool $isAdmin): void
    {
        $player = new Player(Uuid::uuid7(), $code, $userId, null, null, new DateTimeImmutable());
        $player->isAdmin = $isAdmin;

        $this->entityManager->persist($player);
        $this->entityManager->flush();
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
