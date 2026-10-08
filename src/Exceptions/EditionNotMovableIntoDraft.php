<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\UnpublishBlocker;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * MoveEditionToSeries into a draft series of an edition that has participants, official results or linked solving
 * times: a draft never holds those - every listing that reaches events through times or participants would show its
 * name (docs/features/organizations/README.md "Drafts"). An empty edition may move into a draft series.
 */
final class EditionNotMovableIntoDraft extends ConflictHttpException
{
    /**
     * @param list<UnpublishBlocker> $blockers
     */
    public function __construct(
        readonly public array $blockers,
    ) {
        parent::__construct(sprintf(
            'The target series is a draft and the edition has %s - a draft never holds those. Publish the series first, or move an empty edition. Nothing was changed.',
            implode(', ', array_map(static fn (UnpublishBlocker $blocker): string => $blocker->value, $blockers)),
        ));
    }
}
