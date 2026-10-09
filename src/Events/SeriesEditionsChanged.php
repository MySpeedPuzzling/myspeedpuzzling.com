<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * A change that can move series picks between the series' editions (docs/features/events-page/high-frequency-series.md
 * "Where the rule runs and when it reconciles"): an edition created, moved in or out, its dates changed, published,
 * unpublished, rejected or deleted, or the series approved, rejected, published or unpublished. Recorded by Competition
 * and CompetitionSeries, handled on postFlush (routed sync) by SeriesEditionReconciler.
 */
readonly final class SeriesEditionsChanged implements DeduplicatedDomainEvent
{
    public function __construct(
        public UuidInterface $seriesId,
    ) {
    }

    public function deduplicationKey(): string
    {
        return $this->seriesId->toString();
    }
}
