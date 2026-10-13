<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\EventSubscriber\RedactOnlineTokensInExceptionsSubscriber;
use SpeedPuzzling\Web\Services\Logging\OnlineTokenRedactor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The router's 404 / 405 on a personal link's path carry the whole URI - replaced before anything logs them, with the
 * same class (Sentry ignores those by class), status and headers.
 */
final class RedactOnlineTokensInExceptionsSubscriberTest extends TestCase
{
    private const string TOKEN = '2ConnectedTestCupToken';

    public function testTheRoutersNotFoundLosesTheToken(): void
    {
        $event = $this->event(new NotFoundHttpException(
            'No route found for "GET http://localhost/api/compete/' . self::TOKEN . '/nothing"',
            new \RuntimeException('No routes for "/api/compete/' . self::TOKEN . '/nothing".'),
        ));

        (new RedactOnlineTokensInExceptionsSubscriber(new OnlineTokenRedactor(new RequestStack())))->onKernelException($event);

        $exception = $event->getThrowable();
        self::assertInstanceOf(NotFoundHttpException::class, $exception);
        self::assertSame('No route found for "GET http://localhost/api/compete/[redacted]/nothing"', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    public function testTheRoutersMethodNotAllowedKeepsItsAllowHeader(): void
    {
        $event = $this->event(new MethodNotAllowedHttpException(['POST'], 'No route found for "GET http://localhost/api/compete/' . self::TOKEN . '/events": Method Not Allowed (Allow: POST)'));

        (new RedactOnlineTokensInExceptionsSubscriber(new OnlineTokenRedactor(new RequestStack())))->onKernelException($event);

        $exception = $event->getThrowable();
        self::assertInstanceOf(MethodNotAllowedHttpException::class, $exception);
        self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
        self::assertSame(['Allow' => 'POST'], $exception->getHeaders());
    }

    public function testAnExceptionWithoutATokenIsLeftAlone(): void
    {
        $original = new NotFoundHttpException('No route found for "GET http://localhost/en/nothing"');
        $event = $this->event($original);

        (new RedactOnlineTokensInExceptionsSubscriber(new OnlineTokenRedactor(new RequestStack())))->onKernelException($event);

        self::assertSame($original, $event->getThrowable());
    }

    private function event(\Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/api/compete/' . self::TOKEN . '/nothing'),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }
}
