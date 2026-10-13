<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Logging;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Logging\OnlineTokenRedactor;
use SpeedPuzzling\Web\Services\Logging\RedactOnlineTokensProcessor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

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
            datetime: new DateTimeImmutable(),
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

    public function testARecordWithoutATokenIsTheSameRecord(): void
    {
        $record = new LogRecord(new DateTimeImmutable(), 'app', Level::Warning, 'Recording an online heartbeat failed', ['round_id' => 'x']);

        self::assertSame($record, (new RedactOnlineTokensProcessor(new OnlineTokenRedactor(new RequestStack())))($record));
    }
}
