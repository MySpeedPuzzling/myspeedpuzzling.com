<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * The duplicate case the player answered is not open any more, or its copies changed meanwhile (a teammate
 * deleted their copy, the detection closed it). Nothing was changed.
 */
final class DuplicateCaseChanged extends \Exception
{
}
