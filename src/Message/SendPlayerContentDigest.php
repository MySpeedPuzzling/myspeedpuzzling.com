<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\ContentDigestKind;

/**
 * One activity digest or no-activity e-mail for one player and one period (key "2026-W42", "2026-W42-W43",
 * "2026-10"), queued by a run (PlanContentDigestRuns) on the dedicated content_digest_emails queue with a delay that
 * spaces the run over the day. `attempt` counts our own retries after the SMTP server asked us to try later.
 */
readonly final class SendPlayerContentDigest
{
    public function __construct(
        public string $playerId,
        public string $periodKey,
        public ContentDigestKind $kind,
        public string $runId,
        public int $attempt = 0,
    ) {
    }
}
