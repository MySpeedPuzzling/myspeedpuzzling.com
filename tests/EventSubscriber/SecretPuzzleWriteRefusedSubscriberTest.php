<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use DateTimeImmutable;
use SpeedPuzzling\Web\EventSubscriber\SecretPuzzleWriteRefusedSubscriber;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The organiser's "still secret until …" lands on the page they came from - never on another site - and the APIs
 * keep their 409, also behind an encoded path.
 */
final class SecretPuzzleWriteRefusedSubscriberTest extends KernelTestCase
{
    private const string PUZZLE_ID = '018d0003-0000-0000-0000-000000000001';

    public function testTheApisAreLeftAlone(): void
    {
        foreach (['/api/v1/me/solving-times', '/%61pi/v1/me/solving-times', '/internal-api/rounds/x', '/%69nternal-api/rounds/x'] as $path) {
            self::assertNull($this->responseFor(Request::create('https://myspeedpuzzling.com' . $path, 'POST')), $path);
        }

        // Matched by API Platform, whatever the path
        $apiRoute = Request::create('https://myspeedpuzzling.com/somewhere', 'POST');
        $apiRoute->attributes->set('_route', '_api_/v1/me/solving-times_post');
        self::assertNull($this->responseFor($apiRoute));

        $json = Request::create('https://myspeedpuzzling.com/en/wishlist/x/add', 'POST');
        $json->setRequestFormat('json');
        self::assertNull($this->responseFor($json));
    }

    public function testAFullPageGoesBackToTheSitesOwnRefererOnly(): void
    {
        $fromOwnPage = Request::create('https://myspeedpuzzling.com/en/wishlist/x/add', 'POST', server: [
            'HTTP_REFERER' => 'https://myspeedpuzzling.com/en/puzzle/' . self::PUZZLE_ID . '?tab=times',
        ]);
        $response = $this->responseFor($fromOwnPage);
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://myspeedpuzzling.com/en/puzzle/' . self::PUZZLE_ID . '?tab=times', $response->getTargetUrl());

        foreach (['https://evil.example/en/puzzle', 'https://myspeedpuzzling.com.evil.example/x', '//evil.example/x', 'javascript:alert(1)'] as $referer) {
            $response = $this->responseFor(Request::create('https://myspeedpuzzling.com/en/wishlist/x/add', 'POST', server: ['HTTP_REFERER' => $referer]));
            self::assertInstanceOf(RedirectResponse::class, $response, $referer);
            self::assertStringContainsString('/puzzle/' . self::PUZZLE_ID, $response->getTargetUrl(), $referer);
            self::assertStringNotContainsString('evil', $response->getTargetUrl(), $referer);
        }
    }

    public function testATurboFrameGetsTheMessageInItsPlace(): void
    {
        $response = $this->responseFor(Request::create('https://myspeedpuzzling.com/en/wishlist/x/add', 'POST', server: [
            'HTTP_TURBO_FRAME' => 'modal-frame',
        ]));

        self::assertNotNull($response);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<turbo-frame id="modal-frame">', (string) $response->getContent());
        self::assertStringContainsString('you can add it after the reveal', (string) $response->getContent());
    }

    private function responseFor(Request $request): null|\Symfony\Component\HttpFoundation\Response
    {
        self::bootKernel();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push($request);

        try {
            $event = new ExceptionEvent(
                self::$kernel ?? self::bootKernel(),
                $request,
                HttpKernelInterface::MAIN_REQUEST,
                new PuzzleNotRevealedYet(self::PUZZLE_ID, new DateTimeImmutable('+5 days'), 'America/Chicago'),
            );
            self::getContainer()->get(SecretPuzzleWriteRefusedSubscriber::class)->onKernelException($event);

            return $event->getResponse();
        } finally {
            $requestStack->pop();
        }
    }
}
