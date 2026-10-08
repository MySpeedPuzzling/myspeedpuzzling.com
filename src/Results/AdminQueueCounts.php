<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The backlog of every review queue, shown as badges in the key menu (GetAdminQueueCounts). The admin-only queues are
 * null for a moderator - not counted at all, the moderator does not see their links.
 */
readonly final class AdminQueueCounts
{
    public function __construct(
        public int $changeRequests,
        public int $mergeRequests,
        public int $puzzleApprovals,
        public int $timeVerification,
        public null|int $competitionApprovals,
        public null|int $oauth2Requests,
        public null|int $duplicatePuzzleSignals,
    ) {
    }
}
