<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\ComparisonKind;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The line-up of that kind is at its cap (ComparisonLimits) and no row to swap was named - the UI offers the swap.
 */
final class ComparisonLineUpFull extends ConflictHttpException
{
    public function __construct(
        readonly public ComparisonKind $kind,
        readonly public int $cap,
    ) {
        parent::__construct(sprintf('The %s comparison line-up is full (%d)', $kind->value, $cap));
    }
}
