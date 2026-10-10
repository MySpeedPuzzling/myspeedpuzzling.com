<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\ContentDigestKind;

/**
 * One activity digest (periodKey = the period: "2026-W42", "2026-W42-W43", "2026-10") or "We miss you" e-mail
 * (periodKey = the run's key, "miss-you-2026-10-20" - MissYouRun) for one player, queued by a run (PlanContentDigestRuns) on the dedicated content_digest_emails queue with a delay that
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
