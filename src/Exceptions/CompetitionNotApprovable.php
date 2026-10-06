<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Approving a competition that is already approved, rejected, or an edition (approved through its series).
 */
final class CompetitionNotApprovable extends ConflictHttpException
{
}
