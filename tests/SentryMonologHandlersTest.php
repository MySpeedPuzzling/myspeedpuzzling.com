<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use ArrayObject;
use DateTimeImmutable;
use Monolog\Handler\AbstractHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Monolog\BreadcrumbHandler;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Loader\Configurator\AppReference;

/**
 * Regression: on 2026-07-12 the deprecated Sentry\Monolog\Handler was swapped for
 * LogToSentryIssueHandler alone. That handler skips every record carrying an
 * exception, so for two months no uncaught exception and no `'exception' => $e`
 * log call reached Sentry - they only ever showed up in stderr.
 *
 * Rebuilds the production Sentry issue handlers - same classes, same levels as the
 * container defines them - against a recording transport and pushes realistic
 * records through them.
 */
final class SentryMonologHandlersTest extends KernelTestCase
{
    /** Config files call App::config(); the class only exists once Symfony aliases it */
    private const string CONFIG_APP_CLASS = 'Symfony\Component\DependencyInjection\Loader\Configurator\App';

    /** @var ArrayObject<int, Event> */
    private ArrayObject $sentEvents;

    /** @var list<HandlerInterface> */
    private array $productionSentryHandlers = [];

    protected function setUp(): void
    {
        self::bootKernel();

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

        $hub = new Hub(
            // No default integrations: they install global PHP error handlers
            ClientBuilder::create(['dsn' => 'https://public@sentry.example.com/1', 'default_integrations' => false])
                ->setTransport($transport)
                ->getClient(),
        );

        foreach (self::productionSentryIssueHandlers() as $handlerClass => $channels) {
            // Tripled channels would report the same problem twice - see prod/monolog.php
            foreach (['!messenger', '!php', '!sentry_sdk'] as $excludedChannel) {
                self::assertContains($excludedChannel, $channels, "{$handlerClass} must not capture {$excludedChannel}");
            }

            $productionHandler = self::getContainer()->get($handlerClass);
            self::assertInstanceOf(AbstractHandler::class, $productionHandler);

            $handler = new $handlerClass($hub, $productionHandler->getLevel(), true);
            self::assertInstanceOf(HandlerInterface::class, $handler);
            $this->productionSentryHandlers[] = $handler;
        }
    }

    public function testUncaughtExceptionReachesSentryExactlyOnce(): void
    {
        // What Symfony's ErrorListener logs for an uncaught exception
        $this->handle(Level::Critical, 'Uncaught PHP Exception RuntimeException: "A new entity was found"', 'request', new RuntimeException('A new entity was found'));

        $capturedExceptions = $this->theOnlySentEvent()->getExceptions();
        self::assertNotEmpty($capturedExceptions);
        self::assertSame(RuntimeException::class, $capturedExceptions[0]->getType());
    }

    public function testErrorLoggedWithExceptionReachesSentry(): void
    {
        $this->handle(Level::Error, 'Upload to S3 failed', 'app', new RuntimeException('Connection reset'));

        self::assertCount(1, $this->sentEvents);
    }

    public function testErrorMessageWithoutExceptionReachesSentryExactlyOnce(): void
    {
        $this->handle(Level::Error, 'My custom logged error.');

        self::assertSame('My custom logged error.', $this->theOnlySentEvent()->getMessage());
    }

    public function testWarningMessageReachesSentry(): void
    {
        $this->handle(Level::Warning, 'Client failed to load a build asset');

        self::assertSame('Client failed to load a build asset', $this->theOnlySentEvent()->getMessage());
    }

    public function testWarningLoggedWithExceptionReachesSentry(): void
    {
        $this->handle(Level::Warning, 'Result image unavailable - object storage unreachable', 'app', new RuntimeException('Timeout'));

        self::assertCount(1, $this->sentEvents);
    }

    public function testInfoStaysOutOfSentry(): void
    {
        $this->handle(Level::Info, 'Login failed.', 'app', new RuntimeException('Bad credentials'));

        self::assertCount(0, $this->sentEvents);
    }

    private function theOnlySentEvent(): Event
    {
        self::assertCount(1, $this->sentEvents);
        $event = $this->sentEvents[0];
        self::assertInstanceOf(Event::class, $event);

        return $event;
    }

    private function handle(Level $level, string $message, string $channel = 'app', null|RuntimeException $exception = null): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: $channel,
            level: $level,
            message: $message,
            context: $exception === null ? [] : ['exception' => $exception],
        );

        foreach ($this->productionSentryHandlers as $handler) {
            $handler->handle($record);
        }
    }

    /**
     * Top-level handlers of the production logging config that turn records into
     * Sentry issues (breadcrumbs only enrich those issues).
     *
     * @return array<class-string, list<string>> handler class => its channel filter
     */
    private static function productionSentryIssueHandlers(): array
    {
        // What Symfony's PhpFileLoader does before loading a config file
        if (class_exists(self::CONFIG_APP_CLASS) === false) {
            class_alias(AppReference::class, self::CONFIG_APP_CLASS);
        }

        /** @var array{monolog: array{handlers: array<string, array{type: string, id?: string, channels?: list<string>}>}} $config */
        $config = require __DIR__ . '/../config/packages/prod/monolog.php';

        $handlers = [];

        foreach ($config['monolog']['handlers'] as $handler) {
            $serviceId = $handler['id'] ?? '';

            if ($handler['type'] === 'service' && str_starts_with($serviceId, 'Sentry\\Monolog\\') && $serviceId !== BreadcrumbHandler::class) {
                self::assertTrue(class_exists($serviceId), "Unknown Sentry handler {$serviceId}");
                $handlers[$serviceId] = $handler['channels'] ?? [];
            }
        }

        self::assertNotSame([], $handlers, 'Production logging has no Sentry issue handler');

        return $handlers;
    }
}
