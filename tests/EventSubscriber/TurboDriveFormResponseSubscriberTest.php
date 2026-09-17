<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\EventSubscriber\TurboDriveFormResponseSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class TurboDriveFormResponseSubscriberTest extends TestCase
{
    private const string TURBO_FORM_ACCEPT = 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml';

    public function testReportsTurboDriveSubmissionAnsweredWith200(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('answered with 200'), self::callback(
                static fn (array $context): bool => $context['path'] === '/en/claim-voucher' && $context['route'] === 'claim_voucher',
            ));

        $this->dispatch($logger, $this->request('POST', ['HTTP_ACCEPT' => self::TURBO_FORM_ACCEPT]), new Response('<html></html>'));
    }

    /**
     * @return iterable<string, array{Request, Response}>
     */
    public static function answersTurboHandles(): iterable
    {
        yield 'redirect after success' => [self::requestWith('POST', ['HTTP_ACCEPT' => self::TURBO_FORM_ACCEPT]), new Response('', 302)];
        yield '422 re-render of an invalid form' => [self::requestWith('POST', ['HTTP_ACCEPT' => self::TURBO_FORM_ACCEPT]), new Response('<html></html>', 422)];
        yield 'turbo stream answer' => [self::requestWith('POST', ['HTTP_ACCEPT' => self::TURBO_FORM_ACCEPT]), new Response('<turbo-stream></turbo-stream>', 200, ['Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8'])];
        yield 'submission inside a turbo-frame' => [self::requestWith('POST', ['HTTP_ACCEPT' => self::TURBO_FORM_ACCEPT, 'HTTP_TURBO_FRAME' => 'modal-frame']), new Response('<html></html>')];
        yield 'plain browser post (data-turbo="false")' => [self::requestWith('POST', ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml']), new Response('<html></html>')];
        yield 'page visit' => [self::requestWith('GET', ['HTTP_ACCEPT' => self::TURBO_FORM_ACCEPT]), new Response('<html></html>')];
    }

    #[DataProvider('answersTurboHandles')]
    public function testStaysQuietForAnswersTurboHandles(Request $request, Response $response): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $this->dispatch($logger, $request, $response);
    }

    public function testIgnoresSubRequests(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $this->request('POST', ['HTTP_ACCEPT' => self::TURBO_FORM_ACCEPT]),
            HttpKernelInterface::SUB_REQUEST,
            new Response('<html></html>'),
        );

        (new TurboDriveFormResponseSubscriber($logger))->onKernelResponse($event);
    }

    /**
     * @param array<string, string> $server
     */
    private function request(string $method, array $server): Request
    {
        return self::requestWith($method, $server);
    }

    /**
     * @param array<string, string> $server
     */
    private static function requestWith(string $method, array $server): Request
    {
        $request = Request::create('/en/claim-voucher', $method, server: $server);
        $request->attributes->set('_route', 'claim_voucher');

        return $request;
    }

    private function dispatch(LoggerInterface $logger, Request $request, Response $response): void
    {
        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        (new TurboDriveFormResponseSubscriber($logger))->onKernelResponse($event);
    }
}
