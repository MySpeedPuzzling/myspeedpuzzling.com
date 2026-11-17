<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * CopyRoundTableNumbers refused - nothing was written in any round. `problems` name each target round (null = the
 * copy as a whole) and why (seating.copy.reason.*: no_targets, source_without_tables, round_not_available,
 * changed_meanwhile, invalid_table_number).
 */
final class TableNumbersCopyRefused extends UnprocessableEntityHttpException
{
    /**
     * @param list<array{round: null|string, reason: string}> $problems
     */
    public function __construct(
        readonly public array $problems,
    ) {
        parent::__construct('The table numbers were not copied.');
    }
}
