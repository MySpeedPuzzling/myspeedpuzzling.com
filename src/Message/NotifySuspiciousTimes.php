<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The notice run after every scan (docs/features/suspicious-time-review.md, "The notice run"): one notice per
 * registered person of every marked time per mark. Answers the number of notices created.
 *
 * toldByHand: once at go-live (`--existing-marks-told-by-hand`) - every mark existing then was e-mailed by hand, so
 * each notice of this run is recorded as already sent (via manual_email) and neither the banner nor the e-mail ever
 * mentions it.
 */
readonly final class NotifySuspiciousTimes
{
    public function __construct(
        public bool $toldByHand = false,
    ) {
    }
}
