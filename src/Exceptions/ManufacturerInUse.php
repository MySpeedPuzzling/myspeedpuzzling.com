<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Something still points at the brand (a puzzle, a change request proposing it, a merged
 * brand's slug redirecting to it) - deleting it would drop that silently. Merge it instead.
 */
final class ManufacturerInUse extends ConflictHttpException
{
}
