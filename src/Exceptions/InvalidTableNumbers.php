<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * AssignTableNumbers refused - nothing was written. `problems` name each entry and why
 * (official_results.reason.*: entry_not_found, invalid_table_number, duplicate_entry, table_number_taken).
 */
final class InvalidTableNumbers extends UnprocessableEntityHttpException
{
    /**
     * @param list<array{entry: string, reason: string}> $problems
     */
    public function __construct(
        readonly public array $problems,
    ) {
        parent::__construct('The table numbers were not assigned.');
    }
}
