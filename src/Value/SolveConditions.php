<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The conditions of the solve a candidate prediction (v4) is made for, when they are known: an added or reconstructed
 * time says whether it was solved unboxed (without the picture) and at an event. A prediction for a puzzle not solved yet
 * (the Picker, a puzzle page) knows neither - the defaults.
 */
readonly final class SolveConditions
{
    public function __construct(
        public bool $unboxed = false,
        public bool $competition = false,
    ) {
    }
}
