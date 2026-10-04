<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Logging;

use DateTimeImmutable;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Services\Logging\DowngradeRoutineWarningsProcessor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DowngradeRoutineWarningsProcessorTest extends KernelTestCase
{
    public function testCsrfPostWithoutAnyOriginInfoIsInfo(): void
    {
        $record = (new DowngradeRoutineWarningsProcessor())($this->record(Level::Warning, 'CSRF validation failed: double-submit and origin info not found.'));

        self::assertSame(Level::Info, $record->level);
    }

    public function testOtherCsrfFailuresAndOtherLevelsAreLeftAlone(): void
    {
        $processor = new DowngradeRoutineWarningsProcessor();

        foreach (['CSRF validation failed: origin info doesn\'t match.', 'Invalid double-submit CSRF token.'] as $message) {
            self::assertSame(Level::Warning, $processor($this->record(Level::Warning, $message))->level);
        }

        self::assertSame(Level::Error, $processor($this->record(Level::Error, 'CSRF validation failed: double-submit and origin info not found.'))->level);
    }

    public function testAWarningLevelHandlerNoLongerTakesIt(): void
    {
        // What the Sentry issue handlers are: handlers from Warning up
        $handler = new TestHandler(Level::Warning);
        $logger = new Logger('request', [$handler], [new DowngradeRoutineWarningsProcessor()]);

        $logger->warning('CSRF validation failed: double-submit and origin info not found.');
        $logger->warning('CSRF validation failed: origin info doesn\'t match.');

        self::assertCount(1, $handler->getRecords());
        self::assertSame('CSRF validation failed: origin info doesn\'t match.', $handler->getRecords()[0]->message);
    }

    public function testEveryApplicationLoggerRunsTheProcessor(): void
    {
        self::bootKernel();
        $logger = self::getContainer()->get(LoggerInterface::class);

        $processorClasses = array_map(
            static fn (callable $processor): string => is_object($processor) ? $processor::class : '',
            $logger->getProcessors(),
        );

        self::assertContains(DowngradeRoutineWarningsProcessor::class, $processorClasses);
    }

    private function record(Level $level, string $message): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'request',
            level: $level,
            message: $message,
        );
    }
}
