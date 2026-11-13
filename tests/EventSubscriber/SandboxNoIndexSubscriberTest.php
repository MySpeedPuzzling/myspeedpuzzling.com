<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\EventSubscriber\SandboxNoIndexSubscriber;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SandboxNoIndexSubscriberTest extends TestCase
{
    public function testEveryAnswerOfTheSandboxSaysNoindex(): void
    {
        $response = new Response('{}');
        $response->headers->set('X-Robots-Tag', 'index');

        (new SandboxNoIndexSubscriber(new SandboxMode(true)))->onKernelResponse($this->event($response));

        self::assertSame(SandboxNoIndexSubscriber::HEADER_VALUE, $response->headers->get('X-Robots-Tag'));
    }

    public function testProductionIsLeftAlone(): void
    {
        $response = new Response('<html></html>');

        (new SandboxNoIndexSubscriber(new SandboxMode(false)))->onKernelResponse($this->event($response));

        self::assertNull($response->headers->get('X-Robots-Tag'));
    }

    private function event(Response $response): ResponseEvent
    {
        return new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
    }
}
