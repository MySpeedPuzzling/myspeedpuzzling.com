<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ContentDigestFrequency;

/**
 * What a planning did for one frequency: `planned` (queued `recipients` e-mails), `dry_run` (would have), or why
 * nothing - `not_ready` (the XP launch switch, XpFeatureGate, is off), `paused` (the period starts before the first digest period), `stale` (the period's send days are
 * over, e.g. a Monday that is not a send day of this frequency), `not_yet` (the no-activity e-mail goes the day after
 * the send day).
 */
readonly final class ContentDigestRunPlan
{
    public function __construct(
        public ContentDigestFrequency $frequency,
        public string $periodKey,
        public string $outcome,
        public int $recipients = 0,
        public int $spacingMs = 0,
    ) {
    }
}
