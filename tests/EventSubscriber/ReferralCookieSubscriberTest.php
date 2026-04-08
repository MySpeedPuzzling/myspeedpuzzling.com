<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use SpeedPuzzling\Web\EventSubscriber\ReferralCookieSubscriber;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ReferralCookieSubscriberTest extends KernelTestCase
{
    private ReferralCookieSubscriber $subscriber;
    private HttpKernelInterface $httpKernel;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $playerRepository = $container->get(PlayerRepository::class);
        $this->subscriber = new ReferralCookieSubscriber($playerRepository);
        $this->httpKernel = $this->createMock(HttpKernelInterface::class);
    }

    public function testCookieSetOnValidRefCodeWithActiveAffiliate(): void
    {
        // player1 is PLAYER_REGULAR who is in the referral program
        $request = Request::create('/?ref=player1');
        $response = new Response();

        $event = new ResponseEvent(
            $this->httpKernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $this->subscriber->onKernelResponse($event);

        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame(ReferralCookieSubscriber::COOKIE_NAME, $cookies[0]->getName());
        self::assertSame('player1', $cookies[0]->getValue());
        self::assertTrue($cookies[0]->isHttpOnly());
    }

    public function testCookieNotSetWhenPlayerDoesNotExist(): void
    {
        $request = Request::create('/?ref=NONEXISTENT');
        $response = new Response();

        $event = new ResponseEvent(
            $this->httpKernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $this->subscriber->onKernelResponse($event);

        self::assertEmpty($response->headers->getCookies());
    }

    public function testCookieNotSetWhenPlayerNotInReferralProgram(): void
    {
        // player3 = PLAYER_WITH_FAVORITES who is NOT in referral program
        $request = Request::create('/?ref=player3');
        $response = new Response();

        $event = new ResponseEvent(
            $this->httpKernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $this->subscriber->onKernelResponse($event);

        self::assertEmpty($response->headers->getCookies());
    }

    public function testExistingCookieNotOverwritten(): void
    {
        $request = Request::create('/?ref=player1');
        $request->cookies->set(ReferralCookieSubscriber::COOKIE_NAME, 'EXISTING');
        $response = new Response();

        $event = new ResponseEvent(
            $this->httpKernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $this->subscriber->onKernelResponse($event);

        self::assertEmpty($response->headers->getCookies());
    }

    public function testNoCookieSetWhenNoRefParam(): void
    {
        $request = Request::create('/some-page');
        $response = new Response();

        $event = new ResponseEvent(
            $this->httpKernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $this->subscriber->onKernelResponse($event);

        self::assertEmpty($response->headers->getCookies());
    }

    public function testCookieNotSetForSuspendedPlayer(): void
    {
        // player4 = PLAYER_WITH_STRIPE who is suspended from referral program
        $request = Request::create('/?ref=player4');
        $response = new Response();

        $event = new ResponseEvent(
            $this->httpKernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $this->subscriber->onKernelResponse($event);

        self::assertEmpty($response->headers->getCookies());
    }
}
