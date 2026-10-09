<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

/**
 * A domain event whose handler is idempotent and depends only on its key: DomainEventsSubscriber dispatches it once per
 * class and key per flush ("Add several dates" creates 24 editions in one flush - one reconcile of their series, not
 * 24; docs/features/events-page/high-frequency-series.md P4).
 */
interface DeduplicatedDomainEvent
{
    public function deduplicationKey(): string;
}
