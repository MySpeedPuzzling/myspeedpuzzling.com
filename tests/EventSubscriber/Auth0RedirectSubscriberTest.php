<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\EventSubscriber\Auth0RedirectSubscriber;
use SpeedPuzzling\Web\Security\Auth0EntryPoint;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class Auth0RedirectSubscriberTest extends TestCase
{
    private const string AUTH0_AUTHORIZE_URL = 'https://tenant.auth0.com/authorize?client_id=abc';

    public function testCallbackRedirectsToCookieTargetAndClearsCookie(): void
    {
        $request = Request::create('http://localhost/auth/callback');
        $request->cookies->set(Auth0EntryPoint::REDIRECT_COOKIE, 'http://localhost/en/puzzles');

        $event = $this->dispatch($request, new RedirectResponse('http://localhost/'));

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('http://localhost/en/puzzles', $response->getTargetUrl());
        self::assertTrue($this->hasClearedCookie($response, Auth0EntryPoint::REDIRECT_COOKIE));
    }

    public function testLoginRedirectToAuth0SetsRedirectCookieFromSameOriginReferer(): void
    {
        $request = Request::create('http://localhost/login');
        $request->headers->set('referer', 'http://localhost/en/puzzle/some-uuid');

        $event = $this->dispatch($request, new RedirectResponse(self::AUTH0_AUTHORIZE_URL));

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        // The redirect to Auth0 itself must stay untouched
        self::assertSame(self::AUTH0_AUTHORIZE_URL, $response->getTargetUrl());

        $cookie = $this->findCookie($response, Auth0EntryPoint::REDIRECT_COOKIE);
        self::assertNotNull($cookie);
        self::assertSame('http://localhost/en/puzzle/some-uuid', $cookie->getValue());
        self::assertTrue($cookie->isHttpOnly());
    }

    public function testLoginRedirectToAuth0WithoutRefererSetsNoCookie(): void
    {
        $request = Request::create('http://localhost/login');

        $event = $this->dispatch($request, new RedirectResponse(self::AUTH0_AUTHORIZE_URL));

        self::assertNull($this->findCookie($event->getResponse(), Auth0EntryPoint::REDIRECT_COOKIE));
    }

    public function testLoginRedirectToAuth0WithForeignRefererSetsNoCookie(): void
    {
        $request = Request::create('http://localhost/login');
        $request->headers->set('referer', 'https://evil.example.com/phishing');

        $event = $this->dispatch($request, new RedirectResponse(self::AUTH0_AUTHORIZE_URL));

        self::assertNull($this->findCookie($event->getResponse(), Auth0EntryPoint::REDIRECT_COOKIE));
    }

    public function testLoginRefererPointingToLoginItselfIsIgnored(): void
    {
        $request = Request::create('http://localhost/login');
        $request->headers->set('referer', 'http://localhost/login');

        $event = $this->dispatch($request, new RedirectResponse(self::AUTH0_AUTHORIZE_URL));

        self::assertNull($this->findCookie($event->getResponse(), Auth0EntryPoint::REDIRECT_COOKIE));
    }

    public function testExistingCookieIsNotOverwrittenOnLogin(): void
    {
        $request = Request::create('http://localhost/login');
        $request->headers->set('referer', 'http://localhost/en/puzzles');
        $request->cookies->set(Auth0EntryPoint::REDIRECT_COOKIE, 'http://localhost/admin');

        $event = $this->dispatch($request, new RedirectResponse(self::AUTH0_AUTHORIZE_URL));

        // External redirect with cookie present: response stays untouched, cookie kept for the callback
        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(self::AUTH0_AUTHORIZE_URL, $response->getTargetUrl());
        self::assertNull($this->findCookie($response, Auth0EntryPoint::REDIRECT_COOKIE));
    }

    public function testLoginLocalRedirectWithCookieRedirectsToTarget(): void
    {
        $request = Request::create('http://localhost/login');
        $request->cookies->set(Auth0EntryPoint::REDIRECT_COOKIE, 'http://localhost/en/puzzles');

        $event = $this->dispatch($request, new RedirectResponse('http://localhost/'));

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('http://localhost/en/puzzles', $response->getTargetUrl());
        self::assertTrue($this->hasClearedCookie($response, Auth0EntryPoint::REDIRECT_COOKIE));
    }

    public function testOtherPathsAreIgnored(): void
    {
        $request = Request::create('http://localhost/en/puzzles');
        $request->headers->set('referer', 'http://localhost/');

        $originalResponse = new RedirectResponse(self::AUTH0_AUTHORIZE_URL);
        $event = $this->dispatch($request, $originalResponse);

        self::assertSame($originalResponse, $event->getResponse());
        self::assertSame([], $event->getResponse()->headers->getCookies());
    }

    private function dispatch(Request $request, Response $response): ResponseEvent
    {
        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        (new Auth0RedirectSubscriber())->onKernelResponse($event);

        return $event;
    }

    private function findCookie(Response $response, string $name): null|Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name && $cookie->getValue() !== null && $cookie->getValue() !== '') {
                return $cookie;
            }
        }

        return null;
    }

    private function hasClearedCookie(Response $response, string $name): bool
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name && ($cookie->getValue() === null || $cookie->getValue() === '')) {
                return true;
            }
        }

        return false;
    }
}
