<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use RuntimeException;
use SpeedPuzzling\Web\Value\GoogleSheetFetchFailure;

/**
 * A Google sheet's export did not arrive (docs/features/data-import/google-sheets.md "Outcomes"). The message is for
 * the log; the player reads the sentence of `$failure`.
 */
final class GoogleSheetNotFetched extends RuntimeException
{
    public function __construct(
        public readonly GoogleSheetFetchFailure $failure,
        string $message,
        /**
         * Not the player's news but something a person should look at: Google sent us somewhere the export never
         * goes (another host, a private address, redirect after redirect) - it changed, or somebody is in the path
         */
        public readonly bool $unexpected = false,
        null|\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
