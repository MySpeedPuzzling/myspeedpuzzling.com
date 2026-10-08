<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Only an empty organization can be deleted (docs/features/organizations/README.md, P4) - its series and one-time
 * events are moved out (or into another organization) first.
 */
final class OrganizationNotEmpty extends ConflictHttpException
{
    public function __construct(
        readonly public int $seriesCount,
        readonly public int $eventCount,
    ) {
        parent::__construct(sprintf(
            'The organization still has %d series and %d one-time event(s) - move them out of it first.',
            $seriesCount,
            $eventCount,
        ));
    }
}
