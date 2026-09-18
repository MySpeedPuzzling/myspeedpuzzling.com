<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Logging;

use DateTimeImmutable;
use LogicException;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SpeedPuzzling\Web\Message\UpdateMembershipSubscription;
use SpeedPuzzling\Web\Services\Logging\UnwrapMessengerExceptionProcessor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

final class UnwrapMessengerExceptionProcessorTest extends KernelTestCase
{
    public function testHandlerFailureIsLoggedAsTheExceptionTheHandlerThrew(): void
    {
        $cause = new RuntimeException('Stripe API is down');

        $record = (new UnwrapMessengerExceptionProcessor())($this->record(self::handlerFailed($cause)));

        self::assertSame($cause, $record->context['exception']);
        self::assertSame(UpdateMembershipSubscription::class, $record->context['messenger_message']);
        self::assertArrayNotHasKey('other_handler_failures', $record->context);
    }

    public function testNestedDispatchIsUnwrappedDownToTheRealCause(): void
    {
        $cause = new LogicException('Invariant broken in the nested handler');

        $record = (new UnwrapMessengerExceptionProcessor())($this->record(self::handlerFailed(self::handlerFailed($cause))));

        self::assertSame($cause, $record->context['exception']);
    }

    public function testFurtherHandlerFailuresAreKeptAsContext(): void
    {
        $first = new RuntimeException('First handler failed');
        $second = new LogicException('Second handler failed');

        $record = (new UnwrapMessengerExceptionProcessor())($this->record(self::handlerFailed($first, $second)));

        self::assertSame($first, $record->context['exception']);
        self::assertSame([LogicException::class . ': Second handler failed'], $record->context['other_handler_failures']);
    }

    public function testOtherExceptionsAndRecordsAreLeftAlone(): void
    {
        $processor = new UnwrapMessengerExceptionProcessor();
        $plain = $this->record(new RuntimeException('Not from a handler'));
        $withoutException = new LogRecord(new DateTimeImmutable(), 'app', Level::Error, 'No exception', ['id' => 1]);

        self::assertSame($plain, $processor($plain));
        self::assertSame($withoutException, $processor($withoutException));
    }

    public function testEveryApplicationLoggerRunsTheProcessor(): void
    {
        self::bootKernel();
        $logger = self::getContainer()->get(LoggerInterface::class);

        $processorClasses = array_map(
            static fn (callable $processor): string => is_object($processor) ? $processor::class : '',
            $logger->getProcessors(),
        );

        self::assertContains(UnwrapMessengerExceptionProcessor::class, $processorClasses);
    }

    private function record(Throwable $exception): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'request',
            level: Level::Critical,
            message: 'Uncaught PHP Exception ' . $exception::class,
            context: ['exception' => $exception],
        );
    }

    private static function handlerFailed(Throwable ...$failures): HandlerFailedException
    {
        return new HandlerFailedException(new Envelope(new UpdateMembershipSubscription('sub_test')), $failures);
    }
}
