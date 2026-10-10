<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ContentDigestFrequency;

/**
 * What a planning did for one frequency: `planned` (queued `recipients` e-mails), `dry_run` (would have), or why
 * nothing - `not_ready` (the XP launch switch, XpFeatureGate, is off), `paused` (activity: the period starts before the first digest period; "We miss you": before its
 * first run day), `stale` (the period's send days are over, e.g. a Monday that is not a send day of this frequency).
 */
readonly final class ContentDigestRunPlan
{
    /**
     * @param array<string, int> $buckets
     */
    public function __construct(
        public ContentDigestFrequency $frequency,
        public string $periodKey,
        public string $outcome,
        public int $recipients = 0,
        public int $spacingMs = 0,
        /** "We miss you": recipients per recency of their last result (28d-3m, 3-6m, 6-12m, 1-2y) */
        public array $buckets = [],
        /** "We miss you": of the recipients, how many are the holdout (logged, not mailed) */
        public int $heldOut = 0,
    ) {
    }
}
