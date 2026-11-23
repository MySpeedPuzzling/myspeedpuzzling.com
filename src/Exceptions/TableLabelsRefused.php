<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * SetRoundTableLabels refused - nothing was written. `problems` per round: changed_meanwhile (+ `current` = the list
 * it has now), too_few (+ `highest` = the highest table number somebody sits at), no_rounds.
 */
final class TableLabelsRefused extends UnprocessableEntityHttpException
{
    /**
     * @param list<array{round: null|string, reason: string, current?: null|list<string>, highest?: int}> $problems
     */
    public function __construct(
        readonly public array $problems,
    ) {
        parent::__construct('The table labels were not saved.');
    }
}
