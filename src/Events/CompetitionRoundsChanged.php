<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * A change that can move solving times between rounds of a competition: a puzzle attached to or removed
 * from a round, or a round's category changed. Handled on postFlush, so the reconcile sees the change.
 */
readonly final class CompetitionRoundsChanged
{
    public function __construct(
        public UuidInterface $competitionId,
    ) {
    }
}
