<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use SpeedPuzzling\Web\EventSubscriber\ReferralCookieSubscriber;
use SpeedPuzzling\Web\Repository\AffiliateRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\AffiliateFixture;
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
        $affiliateRepository = $container->get(AffiliateRepository::class);
        $this->subscriber = new ReferralCookieSubscriber($affiliateRepository);
        $this->httpKernel = $this->createMock(HttpKernelInterface::class);
    }

    public function testCookieSetOnValidRefCodeWithActiveAffiliate(): void
    {
        $request = Request::create('/?ref=' . AffiliateFixture::AFFILIATE_ACTIVE_CODE);
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
        self::assertSame(AffiliateFixture::AFFILIATE_ACTIVE_CODE, $cookies[0]->getValue());
        self::assertTrue($cookies[0]->isHttpOnly());
    }

    public function testCookieNotSetWhenAffiliateDoesNotExist(): void
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

    public function testCookieNotSetWhenAffiliateIsNotActive(): void
    {
        $request = Request::create('/?ref=' . AffiliateFixture::AFFILIATE_PENDING_CODE);
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
        $request = Request::create('/?ref=' . AffiliateFixture::AFFILIATE_ACTIVE_CODE);
        $request->cookies->set(ReferralCookieSubscriber::COOKIE_NAME, 'EXISTING');
        $response = new Response();

        $event = new ResponseEvent(
            $this->httpKernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $this->subscriber->onKernelResponse($event);

        // No new cookie should be set
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

    public function testCookieNotSetForSuspendedAffiliate(): void
    {
        $request = Request::create('/?ref=' . AffiliateFixture::AFFILIATE_SUSPENDED_CODE);
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
