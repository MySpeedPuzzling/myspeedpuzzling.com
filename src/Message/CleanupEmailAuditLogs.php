<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class CleanupEmailAuditLogs
{
    public function __construct(
        public int $retentionDays,
        /** Restrict the cleanup to one email-type family (e.g. "content_digest"). */
        public null|string $emailTypePrefix = null,
        /** Rows per transaction — the handler re-dispatches itself until a batch comes back short. */
        public int $batchSize = 10_000,
    ) {
    }
}
