<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The suspicious time scan (docs/features/suspicious-time-review.md, "Detection"): refresh the community references,
 * reconcile flags changed by SQL, check the candidates, open / refresh / close cases. A dry run writes nothing and
 * answers the raised rows. Answers Results\SuspiciousTimeScanSummary.
 *
 * onlyPuzzleId: the candidates of one puzzle only, judged with the stored references and nothing reconciled - right
 * after a moderator changed its slow threshold, so its cases close (or open) at once instead of with the next run.
 */
readonly final class DetectSuspiciousTimes
{
    public function __construct(
        public bool $dryRun = false,
        public null|string $onlyPuzzleId = null,
    ) {
    }
}
