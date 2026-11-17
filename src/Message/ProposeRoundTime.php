<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\CompetitorHeartbeatRecord;

/**
 * A competitor's "propose my time" from their own competitor page (competition toolkit P1, toolkit/B-qr-competitor-page.md
 * §5.3-5.4 with R-review-and-plan.md §2.4) - ProposeRoundTimeHandler answers with a TimeProposalAnswer and writes
 * nothing on a refusal. Serialised with every participant write of the event: "no proposal once a result exists" and
 * "one pending per entry" are checked under the same lock as RecordRoundResults.
 *
 * `receivedAtMs` is the server's clock taken by the controller before the dispatch waits for the lock. The timing
 * evidence (reliability hardening T2, TimeProposalTiming) comes along: what the phone says about its clock and its
 * outbox, and `heartbeat` - what the server last heard from the competitor's page for this entry (read by the controller
 * from redis-state before the dispatch; null when nothing was heard or the store did not answer).
 */
readonly final class ProposeRoundTime implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $roundId,
        // RoundEntryRef string
        public string $entry,
        // The phone's UUIDv7, made at the tap
        public string $proposalId,
        public string $actingPlayerId,
        public int $tappedAtMs,
        public int $receivedAtMs,
        public null|float $offsetMs = null,
        public null|float $rttMs = null,
        // RoundClock::stateHash() the phone timed against
        public null|string $stateHash = null,
        // T2: the age of the phone's clock sample at the tap
        public null|int $offsetSampleAgeMs = null,
        // T2: the tap waited on the phone (offline at the tap, or a send failed before this one)
        public bool $queuedOffline = false,
        // T2: which attempt of the phone this is (1 = the first send)
        public null|int $attempts = null,
        // T2: the phone's server-synced clock when it sent this request
        public null|int $sentAtMs = null,
        public null|CompetitorHeartbeatRecord $heartbeat = null,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
