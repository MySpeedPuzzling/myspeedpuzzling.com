<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\EventSubscriber\AuthenticationAuditSubscriber;
use SpeedPuzzling\Web\Security\LoginFormAuthenticator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\LoginLinkAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class AuthenticationAuditSubscriberTest extends KernelTestCase
{
    private AuthenticationAuditSubscriber $subscriber;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->subscriber = $container->get(AuthenticationAuditSubscriber::class);
        $this->connection = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testLoginSuccessIsAudited(): void
    {
        $account = $this->createAccount('msp|subscriber1');

        $this->subscriber->onLoginSuccess($this->loginSuccessEvent($account, $this->formAuthenticator(), 'main'));

        $row = $this->fetchEvent('login_success', $account);

        self::assertSame('form', $row['authenticator']);
        self::assertSame('203.0.113.9', $row['ip_address']);
        self::assertSame('PHPUnit UA', $row['user_agent']);
        self::assertSame($account->email, $row['email']);
    }

    public function testSignInLinkSuccessIsAuditedAsSignInLinkUsed(): void
    {
        $account = $this->createAccount('msp|subscriber2');
        /** @var LoginLinkAuthenticator $loginLinkAuthenticator */
        $loginLinkAuthenticator = self::getContainer()->get('security.authenticator.login_link.main');

        $this->subscriber->onLoginSuccess($this->loginSuccessEvent($account, $loginLinkAuthenticator, 'main'));

        $row = $this->fetchEvent('sign_in_link_used', $account);

        self::assertSame('login_link', $row['authenticator']);
    }

    public function testNonMainFirewallIsIgnored(): void
    {
        $account = $this->createAccount('msp|subscriber3');

        $this->subscriber->onLoginSuccess($this->loginSuccessEvent($account, $this->formAuthenticator(), 'api'));

        self::assertSame(0, $this->countEventsFor($account));
    }

    public function testLoginFailureIsAuditedWithAttemptedEmail(): void
    {
        $email = sprintf('failed+%s@example.com', bin2hex(random_bytes(4)));

        $request = $this->request();
        $session = new Session(new MockArraySessionStorage());
        $session->set(SecurityRequestAttributes::LAST_USERNAME, $email);
        $request->setSession($session);

        $this->subscriber->onLoginFailure(new LoginFailureEvent(
            new BadCredentialsException(),
            $this->formAuthenticator(),
            $request,
            null,
            'main',
        ));

        /** @var false|array<string, mixed> $row */
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM auth_audit_log WHERE event_type = :type AND email = :email',
            ['type' => 'login_failure', 'email' => $email],
        );

        self::assertNotFalse($row);
        self::assertNull($row['user_account_id']);
        self::assertIsString($row['metadata']);
        self::assertSame(['reason' => BadCredentialsException::class], json_decode($row['metadata'], associative: true));
    }

    public function testLogoutIsAudited(): void
    {
        $account = $this->createAccount('msp|subscriber4');

        $this->subscriber->onLogout(new LogoutEvent(
            $this->request(),
            new PostAuthenticationToken($account, 'main', $account->getRoles()),
        ));

        $row = $this->fetchEvent('logout', $account);

        self::assertSame('203.0.113.9', $row['ip_address']);
    }

    private function formAuthenticator(): LoginFormAuthenticator
    {
        return self::getContainer()->get(LoginFormAuthenticator::class);
    }

    private function loginSuccessEvent(
        UserAccount $account,
        AuthenticatorInterface $authenticator,
        string $firewallName,
    ): LoginSuccessEvent {
        $passport = new SelfValidatingPassport(new UserBadge($account->email, static fn(): UserAccount => $account));
        $token = new PostAuthenticationToken($account, $firewallName, $account->getRoles());

        return new LoginSuccessEvent($authenticator, $passport, $token, $this->request(), null, $firewallName);
    }

    private function request(): Request
    {
        return Request::create('/login', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_USER_AGENT' => 'PHPUnit UA',
        ]);
    }

    private function createAccount(string $userId): UserAccount
    {
        $email = sprintf('subscriber+%s@example.com', bin2hex(random_bytes(4)));
        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());

        $this->entityManager->persist($userAccount);
        $this->entityManager->flush();

        return $userAccount;
    }

    private function countEventsFor(UserAccount $account): int
    {
        /** @var int|string $count */
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM auth_audit_log WHERE user_account_id = :id',
            ['id' => $account->id->toString()],
        );

        return (int) $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchEvent(string $eventType, UserAccount $account): array
    {
        /** @var false|array<string, mixed> $row */
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM auth_audit_log WHERE event_type = :type AND user_account_id = :id ORDER BY occurred_at DESC LIMIT 1',
            ['type' => $eventType, 'id' => $account->id->toString()],
        );

        self::assertNotFalse($row, sprintf('Expected a %s audit row', $eventType));

        return $row;
    }
}
