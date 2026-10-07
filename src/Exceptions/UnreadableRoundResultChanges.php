<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * A change set of official results (RoundResultChangesParser) the server cannot answer change by change - no list,
 * too many changes, a change without a usable id. `reason` is the key of the referee's text,
 * `official_results.reason.<reason>` - the exception's own message is for developers only and never shown.
 */
final class UnreadableRoundResultChanges extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
