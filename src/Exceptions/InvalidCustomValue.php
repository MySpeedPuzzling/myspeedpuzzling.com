<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\CustomValueProblem;

/**
 * One value of a player's own field can not be stored as typed (CustomValues::normalise()).
 */
final class InvalidCustomValue extends \Exception
{
    public function __construct(
        public readonly CustomValueProblem $problem,
    ) {
        parent::__construct('Custom value refused: ' . $problem->value);
    }
}
