<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Logging;

use SpeedPuzzling\Web\Tests\TestClock;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Logging\OnlineTokenRedactor;
use SpeedPuzzling\Web\Services\Logging\RedactOnlineTokensProcessor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A log record of a link's request keeps no token - the router's "Matched route" is the usual one.
 */
final class RedactOnlineTokensProcessorTest extends TestCase
{
    private const string TOKEN = 'AnonymousTestCupToken2';

    public function testTheRoutersRecordOfALinksRequest(): void
    {
        $requestStack = new RequestStack();
        $request = Request::create('/api/compete/' . self::TOKEN . '/heartbeat', 'POST');
        $request->attributes->set('_route', 'compete_api_heartbeat');
        $request->attributes->set('token', self::TOKEN);
        $requestStack->push($request);
        $exception = new \RuntimeException('kept as it is');

        $record = (new RedactOnlineTokensProcessor(new OnlineTokenRedactor($requestStack)))(new LogRecord(
            datetime: TestClock::now(),
            channel: 'request',
            level: Level::Info,
            message: 'Matched route "compete_api_heartbeat" for /api/compete/' . self::TOKEN . '/heartbeat.',
            context: [
                'route' => 'compete_api_heartbeat',
                'route_parameters' => ['_route' => 'compete_api_heartbeat', 'token' => self::TOKEN],
                'request_uri' => 'http://localhost/api/compete/' . self::TOKEN . '/heartbeat',
                'exception' => $exception,
            ],
            extra: ['url' => '/api/compete/' . self::TOKEN . '/heartbeat'],
        ));

        self::assertSame('Matched route "compete_api_heartbeat" for /api/compete/[redacted]/heartbeat.', $record->message);
        self::assertSame(['_route' => 'compete_api_heartbeat', 'token' => '[redacted]'], $record->context['route_parameters']);
        self::assertSame('http://localhost/api/compete/[redacted]/heartbeat', $record->context['request_uri']);
        self::assertSame($exception, $record->context['exception']);
        self::assertSame(['url' => '/api/compete/[redacted]/heartbeat'], $record->extra);
    }

    /**
     * An exception in the context stays the same object - its class decides the log level and whether Sentry wants it
     * - only its message and its previous ones' lose the token
     */
    public function testTheExceptionsOfARecordLoseTheTokenInPlace(): void
    {
        $previous = new \RuntimeException('No routes for "/api/compete/' . self::TOKEN . '/nothing".');
        $exception = new NotFoundHttpException('No route found for "GET http://localhost/api/compete/' . self::TOKEN . '/nothing"', $previous);
        $error = new \TypeError('The lookup of ' . self::TOKEN . ' failed');

        $record = (new RedactOnlineTokensProcessor(new OnlineTokenRedactor(new RequestStack())))(new LogRecord(
            datetime: TestClock::now(),
            channel: 'request',
            level: Level::Error,
            message: 'Uncaught PHP Exception Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException: "No route found for "GET http://localhost/api/compete/' . self::TOKEN . '/nothing"" at RouterListener.php line 156',
            context: ['exception' => $exception, 'nested' => ['error' => $error]],
        ));

        self::assertSame($exception, $record->context['exception']);
        self::assertSame('No route found for "GET http://localhost/api/compete/[redacted]/nothing"', $exception->getMessage());
        self::assertSame('No routes for "/api/compete/[redacted]/nothing".', $previous->getMessage());
        // Known from a link's path elsewhere in the record
        self::assertSame('The lookup of [redacted] failed', $error->getMessage());
        self::assertStringNotContainsString(self::TOKEN, $record->message);
    }

    public function testARecordWithoutATokenIsTheSameRecord(): void
    {
        $record = new LogRecord(TestClock::now(), 'app', Level::Warning, 'Recording an online heartbeat failed', ['round_id' => 'x']);

        self::assertSame($record, (new RedactOnlineTokensProcessor(new OnlineTokenRedactor(new RequestStack())))($record));
    }
}
