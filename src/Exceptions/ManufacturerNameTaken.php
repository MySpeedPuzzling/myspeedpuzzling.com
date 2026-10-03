<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Another approved brand already carries this name - the brand is a duplicate
 * to merge, not a new brand to approve.
 */
final class ManufacturerNameTaken extends ConflictHttpException
{
}
