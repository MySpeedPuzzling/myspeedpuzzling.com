<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use ArrayObject;
use DateTimeImmutable;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\AppReference;

/**
 * Regression: on 2026-07-12 the deprecated Sentry\Monolog\Handler was swapped for
 * LogToSentryIssueHandler alone. That handler skips every record carrying an
 * exception, so for two months no uncaught exception and no `'exception' => $e`
 * log call reached Sentry - they only ever showed up in stderr.
 *
 * Builds the Sentry members of the production `grouped` handler against a
 * recording transport and pushes realistic records through them.
 */
final class SentryMonologHandlersTest extends TestCase
{
    /** Config files call App::config(); the class only exists once Symfony aliases it */
    private const string CONFIG_APP_CLASS = 'Symfony\Component\DependencyInjection\Loader\Configurator\App';

    /** @var ArrayObject<int, Event> */
    private ArrayObject $sentEvents;

    /** @var list<HandlerInterface> */
    private array $productionSentryHandlers = [];

    protected function setUp(): void
    {
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

        foreach (self::productionGroupedSentryHandlerClasses() as $handlerClass) {
            // Same level as the service definitions in config/services.php
            $handler = new $handlerClass($hub, Level::Error, true);
            self::assertInstanceOf(HandlerInterface::class, $handler);
            $this->productionSentryHandlers[] = $handler;
        }
    }

    public function testUncaughtExceptionReachesSentryExactlyOnce(): void
    {
        $exception = new RuntimeException('A new entity was found through the relationship');

        // What Symfony's ErrorListener logs for an uncaught exception
        $this->handle(new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'request',
            level: Level::Critical,
            message: 'Uncaught PHP Exception RuntimeException: "A new entity was found through the relationship"',
            context: ['exception' => $exception],
        ));

        $capturedExceptions = $this->theOnlySentEvent()->getExceptions();
        self::assertNotEmpty($capturedExceptions);
        self::assertSame(RuntimeException::class, $capturedExceptions[0]->getType());
    }

    public function testErrorLoggedWithExceptionReachesSentry(): void
    {
        $this->handle(new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'Upload to S3 failed',
            context: ['exception' => new RuntimeException('Connection reset')],
        ));

        self::assertCount(1, $this->sentEvents);
    }

    public function testErrorMessageWithoutExceptionReachesSentryExactlyOnce(): void
    {
        $this->handle(new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'My custom logged error.',
        ));

        self::assertSame('My custom logged error.', $this->theOnlySentEvent()->getMessage());
    }

    public function testWarningsStayOutOfSentry(): void
    {
        $this->handle(new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'app',
            level: Level::Warning,
            message: 'The presented password is invalid.',
            context: ['exception' => new RuntimeException('Bad credentials')],
        ));

        self::assertCount(0, $this->sentEvents);
    }

    private function theOnlySentEvent(): Event
    {
        self::assertCount(1, $this->sentEvents);
        $event = $this->sentEvents[0];
        self::assertInstanceOf(Event::class, $event);

        return $event;
    }

    private function handle(LogRecord $record): void
    {
        foreach ($this->productionSentryHandlers as $handler) {
            $handler->handle($record);
        }
    }

    /**
     * @return list<class-string>
     */
    private static function productionGroupedSentryHandlerClasses(): array
    {
        // What Symfony's PhpFileLoader does before loading a config file
        if (class_exists(self::CONFIG_APP_CLASS) === false) {
            class_alias(AppReference::class, self::CONFIG_APP_CLASS);
        }

        /** @var array{monolog: array{handlers: array<string, array{type: string, id?: string, members?: list<string>}>}} $config */
        $config = require __DIR__ . '/../config/packages/prod/monolog.php';
        $handlers = $config['monolog']['handlers'];

        $classes = [];

        foreach ($handlers['grouped']['members'] ?? [] as $memberName) {
            $member = $handlers[$memberName];
            $serviceId = $member['id'] ?? '';

            if ($member['type'] === 'service' && str_starts_with($serviceId, 'Sentry\\Monolog\\')) {
                self::assertTrue(class_exists($serviceId), "Unknown Sentry handler {$serviceId}");
                $classes[] = $serviceId;
            }
        }

        self::assertNotSame([], $classes, 'Production logging has no Sentry handler in the grouped handler');

        return $classes;
    }
}
