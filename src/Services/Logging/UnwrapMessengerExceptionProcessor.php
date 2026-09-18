<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\Messenger\Exception\EnvelopeAwareExceptionInterface;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Throwable;

/**
 * Logs what actually broke inside a message handler, not Messenger's wrapper.
 *
 * Every synchronous handler failure reaches its caller wrapped in a
 * HandlerFailedException ("Handling X failed: ...") - uncaught in a request, thrown
 * out of a console command, or caught and logged at one of the dispatch sites that
 * handle it. Reported as is, Sentry titles and groups every one of them as
 * HandlerFailedException, whatever went wrong underneath. This puts the handler's
 * own exception into the record instead, so Sentry and the logs carry its real
 * class, message and stack trace; the wrapper only adds which message failed.
 *
 * Reporting only - what callers catch is untouched (UnwrapHttpExceptionMiddleware
 * decides that). Worker failures are unwrapped by the Sentry bundle's
 * MessengerListener, and the messenger channel is kept out of Sentry anyway.
 */
#[AsMonologProcessor]
final readonly class UnwrapMessengerExceptionProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $wrapper = $record->context['exception'] ?? null;

        if (!$wrapper instanceof WrappedExceptionsInterface) {
            return $record;
        }

        // Recursive: a handler that dispatches another message synchronously nests
        // one wrapper inside another. Several entries = several failing handlers.
        $failures = array_values($wrapper->getWrappedExceptions(recursive: true));

        if ($failures === []) {
            return $record;
        }

        $context = $record->context;
        $context['exception'] = $failures[0];

        $envelope = $wrapper instanceof EnvelopeAwareExceptionInterface ? $wrapper->getEnvelope() : null;

        if ($envelope !== null) {
            $context['messenger_message'] = $envelope->getMessage()::class;
        }

        if (count($failures) > 1) {
            $context['other_handler_failures'] = array_map(
                static fn (Throwable $failure): string => $failure::class . ': ' . $failure->getMessage(),
                array_slice($failures, 1),
            );
        }

        return $record->with(context: $context);
    }
}
