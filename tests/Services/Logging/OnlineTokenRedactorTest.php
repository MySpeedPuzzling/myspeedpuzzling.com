<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Logging;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Logging\OnlineTokenRedactor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * No personal link's token in what is logged or reported (README §5.4: the token is the access right).
 */
final class OnlineTokenRedactorTest extends TestCase
{
    private const string TOKEN = '2ConnectedTestCupToken';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function texts(): iterable
    {
        yield 'the API' => ['POST https://myspeedpuzzling.com/api/compete/' . self::TOKEN . '/events', 'POST https://myspeedpuzzling.com/api/compete/[redacted]/events'];
        yield 'the page' => ['/en/compete/' . self::TOKEN, '/en/compete/[redacted]'];
        yield 'the page with a query' => ['/de/compete/' . self::TOKEN . '?x=1', '/de/compete/[redacted]?x=1'];
        yield 'the Czech page' => ['https://myspeedpuzzling.com/soutezit/' . self::TOKEN, 'https://myspeedpuzzling.com/soutezit/[redacted]'];
        yield 'the connect' => ['/cs/compete/' . self::TOKEN . '/connect', '/cs/compete/[redacted]/connect'];
        yield 'JSON-escaped' => ['{"url":"https:\/\/x\/api\/compete\/' . self::TOKEN . '\/state"}', '{"url":"https:\/\/x\/api\/compete\/[redacted]\/state"}'];
        yield 'URL-encoded' => ['/login?return=%2Fen%2Fcompete%2F' . self::TOKEN, '/login?return=%2Fen%2Fcompete%2F[redacted]'];
        yield 'a router message' => ['No route found for "GET http://localhost/api/compete/' . self::TOKEN . '/nothing"', 'No route found for "GET http://localhost/api/compete/[redacted]/nothing"'];
        yield 'two of them' => ['/api/compete/' . self::TOKEN . '/state and /en/compete/AnonymousTestCupToken2', '/api/compete/[redacted]/state and /en/compete/[redacted]'];
        yield 'not a token' => ['/api/compete/not-a-token/state', '/api/compete/not-a-token/state'];
        yield 'longer than a token' => ['/api/compete/' . self::TOKEN . 'x/state', '/api/compete/' . self::TOKEN . 'x/state'];
        yield 'another path' => ['/en/puzzle/' . self::TOKEN, '/en/puzzle/' . self::TOKEN];
    }

    #[DataProvider('texts')]
    public function testTokensInPathsAreRedacted(string $text, string $redacted): void
    {
        self::assertSame($redacted, (new OnlineTokenRedactor(new RequestStack()))->redact($text));
    }

    /**
     * On a link's own request its token is redacted wherever it stands - a statement's parameter, a route parameter
     */
    public function testTheCurrentLinksTokenIsRedactedAnywhere(): void
    {
        $requestStack = new RequestStack();
        $request = Request::create('/api/compete/' . self::TOKEN . '/state');
        $request->attributes->set('_route', 'compete_api_state');
        $request->attributes->set('token', self::TOKEN);
        $requestStack->push($request);
        $redactor = new OnlineTokenRedactor($requestStack);

        self::assertSame('WHERE online_token = [redacted]', $redactor->redact('WHERE online_token = ' . self::TOKEN));
        self::assertSame(
            ['route' => 'compete_api_state', 'route_parameters' => ['token' => '[redacted]', '_route' => 'compete_api_state'], 'id' => 7, 'nested' => ['deep' => ['[redacted]?']]],
            $redactor->redactValue(['route' => 'compete_api_state', 'route_parameters' => ['token' => self::TOKEN, '_route' => 'compete_api_state'], 'id' => 7, 'nested' => ['deep' => [self::TOKEN . '?']]]),
        );
    }

    public function testTokensFoundInPathsAreKnownEverywhere(): void
    {
        $redactor = new OnlineTokenRedactor(new RequestStack());
        $known = $redactor->tokensIn(['url' => 'https://x/api/compete/' . self::TOKEN . '/state', 'other' => ['/soutezit/AnonymousTestCupToken2', 'no token here'], 'object' => new \stdClass()]);

        self::assertSame([self::TOKEN, 'AnonymousTestCupToken2'], $known);
        self::assertSame('param [redacted]', $redactor->redact('param ' . self::TOKEN, $known));
    }

    public function testAnExceptionsMessagesTellTheirTokens(): void
    {
        $redactor = new OnlineTokenRedactor(new RequestStack());
        $exception = new \LogicException('Failed', 0, new \RuntimeException('No routes for "/en/compete/' . self::TOKEN . '/x".'));

        self::assertSame([self::TOKEN], $redactor->tokensIn(['context' => ['exception' => $exception]]));

        $redactor->redactThrowable($exception);
        self::assertSame('Failed', $exception->getMessage());
        self::assertSame('No routes for "/en/compete/[redacted]/x".', $exception->getPrevious()?->getMessage());
    }

    public function testATokenOnAnotherRouteIsJustText(): void
    {
        $requestStack = new RequestStack();
        $request = Request::create('/en/hub');
        $request->attributes->set('_route', 'hub');
        $request->attributes->set('token', self::TOKEN);
        $requestStack->push($request);

        self::assertSame('code ' . self::TOKEN, (new OnlineTokenRedactor($requestStack))->redact('code ' . self::TOKEN));
    }
}
