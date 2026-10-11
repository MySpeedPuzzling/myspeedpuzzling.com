<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ContentDigestKind;

/**
 * Plans today's runs of one kind for every frequency (myspeedpuzzling:send-content-digest): Monday = activity digests
 * of the periods due, Tuesday = "We miss you". A dry run only counts the recipients; `maxRecipients` caps the whole
 * planning (all frequencies together, most engaged first) - for a careful first wave.
 */
readonly final class PlanContentDigestRuns
{
    public function __construct(
        public ContentDigestKind $kind,
        public bool $dryRun = false,
        public null|int $maxRecipients = null,
        /** "We miss you" only: just players whose last result is after / before this moment (first-wave slices) */
        public null|DateTimeImmutable $lastActivityAfter = null,
        public null|DateTimeImmutable $lastActivityBefore = null,
    ) {
    }
}
