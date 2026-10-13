<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * No personal link's token in a log record (OnlineTokenRedactor): the message and every text in the context and extra
 * - the router's "Matched route" with its parameters and the request URI, a statement's parameters, an uncaught
 * exception's message in "Uncaught PHP Exception …" - and the messages of the exceptions in them (the router's 404 /
 * 405 on a link's path carry the whole URI), rewritten in place: the same exception object, so its class, trace and
 * everything else that reads it stay. A token found in a link's path in the record is redacted wherever else it stands
 * in it too. Other objects are left as they are; Sentry is scrubbed on its own (OnlineTokenSentryScrubber).
 */
#[AsMonologProcessor]
final readonly class RedactOnlineTokensProcessor implements ProcessorInterface
{
    private const int MAX_DEPTH = 4;

    public function __construct(
        private OnlineTokenRedactor $redactor,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $known = $this->redactor->tokensIn([$record->message, $record->context, $record->extra]);

        foreach (self::throwables([$record->context, $record->extra]) as $throwable) {
            $this->redactor->redactThrowable($throwable, $known);
        }

        $message = $this->redactor->redact($record->message, $known);
        $context = $this->redactor->redactValue($record->context, $known);
        $extra = $this->redactor->redactValue($record->extra, $known);

        if ($message === $record->message && $context === $record->context && $extra === $record->extra) {
            return $record;
        }

        /** @var array<mixed> $context */
        /** @var array<mixed> $extra */
        return $record->with(message: $message, context: $context, extra: $extra);
    }

    /**
     * @return list<\Throwable>
     */
    private static function throwables(mixed $value, int $depth = 0): array
    {
        if ($value instanceof \Throwable) {
            return [$value];
        }

        if (!is_array($value) || $depth >= self::MAX_DEPTH) {
            return [];
        }

        $throwables = [];

        foreach ($value as $item) {
            array_push($throwables, ...self::throwables($item, $depth + 1));
        }

        return $throwables;
    }
}
