<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\CustomValueProblem;

/**
 * The values sent for a player's own fields were refused as a whole - nothing was stored
 * (SetSolvingTimeCustomValuesHandler). Keyed by the field id as it was sent.
 */
final class InvalidCustomValues extends \Exception
{
    /**
     * @param array<string, CustomValueProblem> $problems
     */
    public function __construct(
        public readonly array $problems,
    ) {
        parent::__construct('Custom values refused for ' . count($problems) . ' field(s).');
    }
}
