<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\RegistrationAvailability;

/**
 * A new registration to an event with managed registration outside its window, or to an event that is not publicly
 * visible. Thrown before the handler changes anything.
 */
final class RegistrationNotOpen extends \Exception
{
    public function __construct(
        public readonly RegistrationAvailability $availability,
    ) {
        parent::__construct(sprintf('Registration is not open (%s)', $availability->value));
    }
}
