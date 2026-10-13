<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * No personal link's token in a log record (OnlineTokenRedactor): the message and every text in the context and extra
 * - the router's "Matched route" with its parameters and the request URI, a statement's parameters, an uncaught
 * exception's message in "Uncaught PHP Exception …". A token found in a link's path in the record is redacted wherever
 * else it stands in it too. Objects (an exception in the context) are left as they are: the router's exceptions on a
 * link's paths are replaced before they are logged (RedactOnlineTokensInExceptionsSubscriber), and Sentry is scrubbed
 * on its own (OnlineTokenSentryScrubber).
 */
#[AsMonologProcessor]
final readonly class RedactOnlineTokensProcessor implements ProcessorInterface
{
    public function __construct(
        private OnlineTokenRedactor $redactor,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $known = $this->redactor->tokensIn([$record->message, $record->context, $record->extra]);

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
}
