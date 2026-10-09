<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * A change that can move solving times between rounds of a competition - or, for an edition, series picks between the
 * editions of its series: a round created, deleted, its category, start or zone changed; a puzzle attached to or
 * removed from a round; a round puzzle's reveal changed. Handled on postFlush, so the reconcile sees the change
 * (SeriesEditionReconciler::reconcileCompetition()).
 */
readonly final class CompetitionRoundsChanged implements DeduplicatedDomainEvent
{
    public function __construct(
        public UuidInterface $competitionId,
    ) {
    }

    public function deduplicationKey(): string
    {
        return $this->competitionId->toString();
    }
}
