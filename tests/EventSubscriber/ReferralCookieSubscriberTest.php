<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use SpeedPuzzling\Web\EventSubscriber\ReferralCookieSubscriber;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReferralCookieSubscriberTest extends KernelTestCase
{
    private ReferralCookieSubscriber $subscriber;
    private HttpKernelInterface $httpKernel;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $playerRepository = $container->get(PlayerRepository::class);
        $translator = $container->get(TranslatorInterface::class);
        $requestStack = $container->get(RequestStack::class);
        $this->subscriber = new ReferralCookieSubscriber($playerRepository, $translator, $requestStack);
        $this->httpKernel = $this->createMock(HttpKernelInterface::class);
    }

    public function testRedirectsAndSetsCookieOnValidRefCode(): void
    {
        $request = Request::create('/?ref=player1');
        $event = new RequestEvent($this->httpKernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/', $response->getTargetUrl());

        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame(ReferralCookieSubscriber::COOKIE_NAME, $cookies[0]->getName());
        self::assertSame('player1', $cookies[0]->getValue());
        self::assertTrue($cookies[0]->isHttpOnly());
    }

    public function testRedirectStripsRefButKeepsOtherParams(): void
    {
        $request = Request::create('/en/membership?ref=player1&foo=bar');
        $event = new RequestEvent($this->httpKernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/en/membership?foo=bar', $response->getTargetUrl());
    }

    public function testNoRedirectWhenPlayerDoesNotExist(): void
    {
        $request = Request::create('/?ref=NONEXISTENT');
        $event = new RequestEvent($this->httpKernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testNoRedirectWhenPlayerNotInReferralProgram(): void
    {
        $request = Request::create('/?ref=player3');
        $event = new RequestEvent($this->httpKernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testNoRedirectWhenCookieAlreadyExists(): void
    {
        $request = Request::create('/?ref=player1');
        $request->cookies->set(ReferralCookieSubscriber::COOKIE_NAME, 'EXISTING');
        $event = new RequestEvent($this->httpKernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testNoRedirectWhenNoRefParam(): void
    {
        $request = Request::create('/some-page');
        $event = new RequestEvent($this->httpKernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testNoRedirectForSuspendedPlayer(): void
    {
        $request = Request::create('/?ref=player4');
        $event = new RequestEvent($this->httpKernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }
}
