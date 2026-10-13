<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Sentry;

use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;
use Sentry\State\Hub;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;
use SpeedPuzzling\Web\Services\Logging\OnlineTokenRedactor;
use SpeedPuzzling\Web\Services\Sentry\OnlineTokenSentryScrubber;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Nothing sent to Sentry carries a personal link's token - `send_default_pii` sends the URL, the headers (the phone's
 * Referer is its competition page) and the body.
 */
final class OnlineTokenSentryScrubberTest extends TestCase
{
    private const string TOKEN = '2ConnectedTestCupToken';

    public function testAnErrorEventIsScrubbedEverywhere(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://myspeedpuzzling.com/api/compete/' . self::TOKEN . '/events',
            'method' => 'POST',
            'headers' => ['Referer' => ['https://myspeedpuzzling.com/en/compete/' . self::TOKEN], 'Accept' => ['application/json']],
        ]);
        $event->setMessage('Failed for /api/compete/%s/media', [self::TOKEN], 'Failed for /api/compete/' . self::TOKEN . '/media');
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException('No route found for "GET /api/compete/' . self::TOKEN . '/x"'))]);
        $event->setExtra(['url' => '/soutezit/' . self::TOKEN]);
        $event->setTags(['route_path' => '/en/compete/' . self::TOKEN]);
        $event->setContext('trace', ['data' => ['http.url' => '/api/compete/' . self::TOKEN . '/state']]);
        $event->setTransaction('GET /api/compete/' . self::TOKEN . '/state');
        $event->setBreadcrumb([
            new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', 'GET /en/compete/' . self::TOKEN, ['url' => '/api/compete/' . self::TOKEN . '/heartbeat']),
        ]);

        $scrubbed = (new OnlineTokenSentryScrubber(new OnlineTokenRedactor(new RequestStack())))->beforeSend()($event, null);

        $everything = (string) json_encode([
            $scrubbed->getRequest(),
            $scrubbed->getMessage(),
            $scrubbed->getMessageParams(),
            $scrubbed->getMessageFormatted(),
            array_map(static fn (ExceptionDataBag $exception): string => $exception->getValue(), $scrubbed->getExceptions()),
            $scrubbed->getExtra(),
            $scrubbed->getTags(),
            $scrubbed->getContexts(),
            $scrubbed->getTransaction(),
            array_map(static fn (Breadcrumb $breadcrumb): array => [$breadcrumb->getMessage(), $breadcrumb->getMetadata()], $scrubbed->getBreadcrumbs()),
        ], JSON_UNESCAPED_SLASHES);

        self::assertStringNotContainsString(self::TOKEN, $everything);
        self::assertStringContainsString('/api/compete/[redacted]/events', $everything);
        self::assertStringContainsString('/en/compete/[redacted]', $everything);
        self::assertStringContainsString('application/json', $everything);
    }

    public function testATransactionsSpansAreScrubbed(): void
    {
        $span = (new Hub())->startTransaction(TransactionContext::make())->startChild(
            SpanContext::make()
                ->setDescription('POST /api/compete/' . self::TOKEN . '/heartbeat')
                ->setData(['url' => 'https://myspeedpuzzling.com/api/compete/' . self::TOKEN . '/heartbeat']),
        );
        $transaction = Event::createTransaction();
        $transaction->setSpans([$span]);

        (new OnlineTokenSentryScrubber(new OnlineTokenRedactor(new RequestStack())))->beforeSend()($transaction, null);

        self::assertSame('POST /api/compete/[redacted]/heartbeat', $span->getDescription());
        self::assertSame(['url' => 'https://myspeedpuzzling.com/api/compete/[redacted]/heartbeat'], $span->getData());
    }

    /**
     * PHP keeps the arguments of every frame (`zend.exception_ignore_args` is off) and Sentry sends them: the
     * controller's and OnlineAccess::forToken()'s `$token` among them
     */
    public function testTheArgumentsOfAnExceptionsFramesAreScrubbed(): void
    {
        $frames = new Stacktrace([
            new Frame('Symfony\\Component\\HttpKernel\\HttpKernel::handleRaw', 'vendor/symfony/http-kernel/HttpKernel.php', 181, vars: ['request' => 'Object Symfony\\Component\\HttpFoundation\\Request']),
            new Frame('SpeedPuzzling\\Web\\Controller\\Compete\\ApiStateController::__invoke', 'src/Controller/Compete/ApiStateController.php', 60, vars: ['request' => 'Object Symfony\\Component\\HttpFoundation\\Request', 'token' => self::TOKEN]),
            new Frame('SpeedPuzzling\\Web\\Services\\OnlineRounds\\OnlineAccess::forToken', 'src/Services/OnlineRounds/OnlineAccess.php', 40, vars: ['token' => self::TOKEN, 'nested' => ['path' => '/en/compete/' . self::TOKEN]]),
        ]);
        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://myspeedpuzzling.com/api/compete/' . self::TOKEN . '/state']);
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException('Connection refused'), $frames)]);

        (new OnlineTokenSentryScrubber(new OnlineTokenRedactor(new RequestStack())))->beforeSend()($event, null);

        self::assertSame(
            [
                ['request' => 'Object Symfony\\Component\\HttpFoundation\\Request'],
                ['request' => 'Object Symfony\\Component\\HttpFoundation\\Request', 'token' => '[redacted]'],
                ['token' => '[redacted]', 'nested' => ['path' => '/en/compete/[redacted]']],
            ],
            array_map(static fn (Frame $frame): array => $frame->getVars(), $frames->getFrames()),
        );
    }

    /**
     * Captured after the response (kernel.terminate, a worker) the event has no request - a link's path in a frame
     * tells the token, a message's own stack trace is scrubbed too
     */
    public function testATokenKnownOnlyFromAFramesPathIsRedactedInTheOtherFrames(): void
    {
        $frames = new Stacktrace([
            new Frame('Symfony\\Component\\Routing\\Matcher\\UrlMatcher::match', 'vendor/symfony/routing/Matcher/UrlMatcher.php', 80, vars: ['pathinfo' => '/api/compete/' . self::TOKEN . '/media']),
            new Frame('SpeedPuzzling\\Web\\Services\\OnlineRounds\\OnlineAccess::forToken', 'src/Services/OnlineRounds/OnlineAccess.php', 40, vars: ['token' => self::TOKEN]),
        ]);
        $event = Event::createEvent();
        $event->setMessage('Something slow');
        $event->setStacktrace($frames);

        (new OnlineTokenSentryScrubber(new OnlineTokenRedactor(new RequestStack())))->beforeSend()($event, null);

        self::assertSame(
            [['pathinfo' => '/api/compete/[redacted]/media'], ['token' => '[redacted]']],
            array_map(static fn (Frame $frame): array => $frame->getVars(), $frames->getFrames()),
        );
    }

    public function testABreadcrumbIsScrubbedOnItsWay(): void
    {
        $breadcrumb = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'request', 'Matched route for /api/compete/' . self::TOKEN . '/state', ['request_uri' => '/api/compete/' . self::TOKEN . '/state']);

        $scrubbed = (new OnlineTokenSentryScrubber(new OnlineTokenRedactor(new RequestStack())))->beforeBreadcrumb()($breadcrumb);

        self::assertSame('Matched route for /api/compete/[redacted]/state', $scrubbed->getMessage());
        self::assertSame(['request_uri' => '/api/compete/[redacted]/state'], $scrubbed->getMetadata());
    }
}
