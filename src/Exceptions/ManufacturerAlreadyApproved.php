<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ManufacturerAlreadyApproved extends ConflictHttpException
{
}
