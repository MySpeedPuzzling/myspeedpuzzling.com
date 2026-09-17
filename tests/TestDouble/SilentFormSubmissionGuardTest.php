<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SilentFormSubmissionGuardTest extends TestCase
{
    public function testFailsFormPostAnsweredWith200Page(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('route "claim_voucher"');

        $this->respond($this->formPost('claim_voucher'), new Response('<html></html>'));
    }

    public function testAllowsRedirectsAnd422(): void
    {
        self::assertSame(302, $this->respond($this->formPost('claim_voucher'), new Response('', 302))->getStatusCode());
        self::assertSame(422, $this->respond($this->formPost('claim_voucher'), new Response('<html></html>', 422))->getStatusCode());
    }

    public function testAllowsFramesJsonAndNativeFormRoutes(): void
    {
        $frame = $this->formPost('claim_voucher');
        $frame->headers->set('Turbo-Frame', 'modal-frame');
        self::assertSame(200, $this->respond($frame, new Response('<html></html>'))->getStatusCode());

        $json = Request::create('/api/v1/me/solving-times', 'POST', server: ['CONTENT_TYPE' => 'application/json']);
        self::assertSame(200, $this->respond($json, new Response('<html></html>'))->getStatusCode());

        self::assertSame(200, $this->respond($this->formPost('confirm_account_deletion'), new Response('<html></html>'))->getStatusCode());
    }

    private function formPost(string $route): Request
    {
        $request = Request::create('/somewhere', 'POST', ['field' => 'value'], server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
        $request->attributes->set('_route', $route);

        return $request;
    }

    private function respond(Request $request, Response $response): Response
    {
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');

        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        (new SilentFormSubmissionGuard())->onKernelResponse($event);

        return $event->getResponse();
    }
}
