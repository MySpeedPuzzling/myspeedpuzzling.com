<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\EventSubscriber\BotTrustCookieSubscriber;
use SpeedPuzzling\Web\Query\GetBotTrustEligibility;
use SpeedPuzzling\Web\Services\BotTrustCookieSigner;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The subscriber with the real eligibility query on the test database. The feature is off in the test env (empty
 * CHALLENGE_COOKIE_SECRET - every functional test would otherwise query and set the cookie), so the subscriber is
 * built here with a secret of its own.
 */
final class BotTrustCookieEligibilityIntegrationTest extends KernelTestCase
{
    private const string SECRET = 'test-secret-for-integration-tests';

    public function testEligibleAccountGetsTheCookie(): void
    {
        self::bootKernel();
        $account = $this->seedAccount(TestClock::now(), verified: true);

        $response = $this->respond($account, null);

        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame(BotTrustCookieSigner::COOKIE_NAME, $cookies[0]->getName());
        self::assertFalse($cookies[0]->isCleared());
        self::assertNotNull(
            new BotTrustCookieSigner(self::SECRET)->parseIssuedAt(
                (string) $cookies[0]->getValue(),
                BotTrustCookieSigner::uidFor($account->userId),
            ),
        );
    }

    public function testIneligibleAccountGetsNoCookieAndLosesTheOneItCarries(): void
    {
        self::bootKernel();
        $account = $this->seedAccount(TestClock::now(), verified: false);

        self::assertSame([], $this->respond($account, null)->headers->getCookies());

        // A cookie minted before the eligibility rule existed
        $old = new BotTrustCookieSigner(self::SECRET)->buildAt(
            BotTrustCookieSigner::uidFor($account->userId),
            BotTrustCookieSigner::TRUST_REVALIDATE_ISSUED_BEFORE - 1,
        );
        $cookies = $this->respond($account, $old)->headers->getCookies();

        self::assertCount(1, $cookies);
        self::assertSame(BotTrustCookieSigner::COOKIE_NAME, $cookies[0]->getName());
        self::assertTrue($cookies[0]->isCleared());
    }

    private function respond(UserAccount $account, null|string $existingCookie): Response
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($account, 'main', $account->getRoles()));

        $subscriber = new BotTrustCookieSubscriber(
            new BotTrustCookieSigner(self::SECRET),
            $tokenStorage,
            self::getContainer()->get(GetBotTrustEligibility::class),
        );

        $request = Request::create('https://myspeedpuzzling.com/en/puzzle');
        if ($existingCookie !== null) {
            $request->cookies->set(BotTrustCookieSigner::COOKIE_NAME, $existingCookie);
        }

        $event = new ResponseEvent(
            self::$kernel ?? throw new \LogicException('Kernel not booted'),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new Response(),
        );
        $subscriber->onKernelResponse($event);

        return $event->getResponse();
    }

    private function seedAccount(DateTimeImmutable $registeredAt, bool $verified): UserAccount
    {
        $userId = 'msp|bbtrust-int-' . bin2hex(random_bytes(4));
        $account = new UserAccount(Uuid::uuid7(), $userId, $userId . '@example.com', $registeredAt);

        if ($verified) {
            $account->markEmailVerified(TestClock::now());
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($account);
        $entityManager->flush();

        return $account;
    }
}
