<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CustomFields;

use Doctrine\DBAL\Exception\DriverException;

/**
 * What may be logged or reported about a failed write of a player's private values: which exceptions, and the
 * database's error code - never their messages. A driver's message quotes the statement's data (Postgres:
 * `CONTEXT: JSON data, line 1: {"…` with the start of the value), and the log goes to Loki and Sentry.
 *
 * The exception made here has no previous one and its trace is where it was made - the original's chain would carry
 * the same message again.
 */
final class CustomValuesWriteFailure extends \RuntimeException
{
    public static function withoutValues(\Throwable $exception): self
    {
        $classes = [];
        $sqlState = null;

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $classes[] = $current::class;

            if ($current instanceof DriverException) {
                $sqlState ??= $current->getSQLState();
            }
        }

        return new self(sprintf(
            'Custom values write failed: %s%s (message withheld: it can quote the values)',
            implode(' <- ', $classes),
            $sqlState !== null ? ', SQLSTATE ' . $sqlState : '',
        ));
    }
}
