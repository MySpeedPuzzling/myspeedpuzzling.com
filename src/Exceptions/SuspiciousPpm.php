<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\SolvingTime;

final class SuspiciousPpm extends \Exception
{
    public function __construct(
        readonly public SolvingTime $solvingTime,
        readonly public float $ppm,
    ) {
        parent::__construct();
    }
}
