<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use ArrayObject;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Monolog\ExceptionToSentryIssueHandler;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\InsufficientAuthenticationException;
use Throwable;

/**
 * Why `ignore_exceptions` lists the Security AccessDeniedException and NOT the
 * HttpKernel AccessDeniedHttpException that the request log actually shows.
 *
 * Every denial the firewall turns into a 403/401 reaches the log as an HttpKernel
 * exception whose previous-chain holds the Security AccessDeniedException, and the
 * SDK drops an event when ANY exception of its chain is ignored. So the Security
 * class alone keeps all of them out: controller checks, voters, #[CurrentUser]
 * type mismatches, access_control role checks and the stateless firewalls' 401.
 *
 * Ignoring AccessDeniedHttpException itself would additionally silence the 403s
 * the application throws on its own - API membership gates, and ones that point
 * at bad data ("Player account has no linked user login.") - which stay visible.
 */
final class SentryAccessDenialsTest extends KernelTestCase
{
    /** @var ArrayObject<int, Event> */
    private ArrayObject $sentEvents;

    private ExceptionToSentryIssueHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $productionOptions = self::getContainer()->get('sentry.client.options');

        $this->sentEvents = new ArrayObject();

        $transport = new class ($this->sentEvents) implements TransportInterface {
            /** @param ArrayObject<int, Event> $sentEvents */
            public function __construct(private readonly ArrayObject $sentEvents)
            {
            }

            public function send(Event $event): Result
            {
                $this->sentEvents->append($event);

                return new Result(ResultStatus::success(), $event);
            }

            public function close(null|int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };

        $client = ClientBuilder::create([
            'dsn' => 'https://public@sentry.example.com/1',
            // No default integrations: they install global PHP error handlers
            'default_integrations' => false,
            'ignore_exceptions' => $productionOptions->getIgnoreExceptions(),
        ])->setTransport($transport)->getClient();

        $this->handler = new ExceptionToSentryIssueHandler(new Hub($client), Level::Warning, true);
    }

    public function testDenialOfASignedInUserStaysOutOfSentry(): void
    {
        // What Security's ExceptionListener throws for a fully authenticated user who
        // fails a check - createAccessDeniedException(), a voter, #[IsGranted], or
        // a #[CurrentUser] argument of the wrong user class
        $this->logUncaught(new AccessDeniedHttpException('Access Denied.', new AccessDeniedException('Access Denied.')));

        self::assertCount(0, $this->sentEvents);
    }

    public function testMissingTokenOnAStatelessFirewallStaysOutOfSentry(): void
    {
        // /internal-api or /api/v1 without a valid token: no entry point, so the
        // ExceptionListener answers 401 with the denial two levels down the chain
        $denial = new AccessDeniedException("Access Denied. The user doesn't have ROLE_INTERNAL_API.");
        $this->logUncaught(new HttpException(
            Response::HTTP_UNAUTHORIZED,
            'Full authentication is required to access this resource.',
            new InsufficientAuthenticationException('Full authentication is required to access this resource.', 0, $denial),
        ));

        self::assertCount(0, $this->sentEvents);
    }

    public function testForbiddenRaisedByTheApplicationItselfIsStillReported(): void
    {
        $this->logUncaught(new AccessDeniedHttpException('Player account has no linked user login.'));

        self::assertCount(1, $this->sentEvents);
    }

    private function logUncaught(Throwable $exception): void
    {
        // What Symfony's ErrorListener logs for an uncaught 4xx HttpException
        $this->handler->handle(new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'request',
            level: Level::Error,
            message: sprintf('Uncaught PHP Exception %s: "%s"', $exception::class, $exception->getMessage()),
            context: ['exception' => $exception],
        ));
    }
}
