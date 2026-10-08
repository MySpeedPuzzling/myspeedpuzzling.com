<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\UnpublishBlocker;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * An event (or series) goes back to draft only while nobody joined it and no result or solving time is linked to it
 * (docs/features/organizations/README.md "Drafts", UnpublishBlockers).
 */
final class CannotUnpublish extends ConflictHttpException
{
    /**
     * @param list<UnpublishBlocker> $blockers
     */
    public function __construct(
        readonly public array $blockers,
    ) {
        parent::__construct(sprintf(
            'It cannot go back to draft: %s.',
            implode(', ', array_map(static fn (UnpublishBlocker $blocker): string => $blocker->value, $blockers)),
        ));
    }
}
