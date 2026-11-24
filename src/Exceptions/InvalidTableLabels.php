<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A list of table labels (or the generator's settings) refused - nothing was written. `problems` name each label and
 * why: empty_list, too_many, empty, too_long, characters, duplicate (+ `first` = the label it repeats); the
 * generator's: letters, numbers, seats, seat_style.
 */
final class InvalidTableLabels extends UnprocessableEntityHttpException
{
    /**
     * @param list<array{label: string, reason: string, first?: string}> $problems
     */
    public function __construct(
        readonly public array $problems,
    ) {
        parent::__construct('The table labels were refused.');
    }
}
