<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\ContentDigestKind;

/**
 * Plans today's runs of one kind for every frequency whose period is due (myspeedpuzzling:send-content-digest):
 * Monday = activity digests, the day after = no-activity e-mails. A dry run only counts the recipients.
 */
readonly final class PlanContentDigestRuns
{
    public function __construct(
        public ContentDigestKind $kind,
        public bool $dryRun = false,
    ) {
    }
}
