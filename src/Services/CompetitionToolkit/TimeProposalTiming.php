<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Entity\CompetitionTimeProposal;
use SpeedPuzzling\Web\Value\CompetitorHeartbeatRecord;
use SpeedPuzzling\Web\Value\TimeProposalTimingVerdict;
use SpeedPuzzling\Web\Value\TimeProposalVerdict;

/**
 * Whether a competitor's tap can be trusted (reliability hardening T2, docs/features/competitions-management/
 * competitor-pages.md "Timing you can trust"). The server knows for sure only when it received something - the proposal,
 * a heartbeat of the competitor's page; the tap moment and the clock are the phone's word. The honest page always tells
 * the truth, so the tap is trusted unless the phone's own words contradict it:
 *
 * - received ≤ 5 s after the tap → on_time;
 * - a clean heartbeat (the page held no unsent tap, none in the confirm sheet) built more than 2 s after the tap, and
 *   received more than 5 s before the proposal → suspicious: the phone was online, held nothing, and the tap came later;
 * - else → offline: an unreachable phone, a confirm sheet left open, no heartbeat at all - nothing to show the judge.
 *   A heartbeat that said "I hold an unsent tap from T" never counts against T - the phone was trying.
 *
 * Whatever the verdict, the recorded time is the tap's (Jan: never the receipt); `suspicious` only makes the judge's
 * card warn and offer the received time. `clockUncertain` is evidence about the clock, a note for the judge (never a
 * refusal - `clock_ahead` stays the only clock refusal): the phone's offset differs from its last heartbeat's by more
 * than 2 s, or it claims to have sent the proposal more than 2 s after we received it. The phone's rtt and sample age
 * are stored with the proposal as diagnostics only (no evidence of anything on their own).
 *
 * Pure - the controller reads the heartbeat record before the dispatch and the message carries it.
 */
readonly final class TimeProposalTiming
{
    // A heartbeat built this little after the tap may have been on its way already
    public const int HEARTBEAT_AFTER_TAP_MS = 2000;
    // The proposal must have come this long after that heartbeat - a phone reconnecting sends both at once
    public const int SILENCE_AFTER_HEARTBEAT_MS = 5000;
    public const int OFFSET_JUMP_MS = 2000;
    public const int SENT_AHEAD_MS = 2000;

    public static function judge(
        int $tappedAtMs,
        int $receivedAtMs,
        null|CompetitorHeartbeatRecord $heartbeat,
        null|float $offsetMs,
        null|int $sentAtMs,
    ): TimeProposalTimingVerdict {
        $clockUncertain = self::clockUncertain($receivedAtMs, $heartbeat, $offsetMs, $sentAtMs);
        $lastHeardAtMs = $heartbeat?->lastHeardAtMs;

        if ($receivedAtMs - $tappedAtMs <= CompetitionTimeProposal::LATE_AFTER_MS) {
            return new TimeProposalTimingVerdict(TimeProposalVerdict::OnTime, $lastHeardAtMs, $clockUncertain);
        }

        $cleanAtMs = $heartbeat?->cleanAtMs;
        $cleanHeardAtMs = $heartbeat?->cleanHeardAtMs;

        if (
            $cleanAtMs !== null && $cleanHeardAtMs !== null
            && $cleanHeardAtMs <= $receivedAtMs
            && $cleanAtMs > $tappedAtMs + self::HEARTBEAT_AFTER_TAP_MS
            && $receivedAtMs - $cleanHeardAtMs > self::SILENCE_AFTER_HEARTBEAT_MS
        ) {
            return new TimeProposalTimingVerdict(TimeProposalVerdict::Suspicious, $cleanHeardAtMs, $clockUncertain);
        }

        return new TimeProposalTimingVerdict(TimeProposalVerdict::Offline, $lastHeardAtMs, $clockUncertain);
    }

    private static function clockUncertain(
        int $receivedAtMs,
        null|CompetitorHeartbeatRecord $heartbeat,
        null|float $offsetMs,
        null|int $sentAtMs,
    ): bool {
        // The clock behind the tap jumped against the one of the page's last heartbeat
        if ($offsetMs !== null && $heartbeat?->lastOffsetMs !== null && abs($offsetMs - $heartbeat->lastOffsetMs) > self::OFFSET_JUMP_MS) {
            return true;
        }

        // The phone says it sent this later than we received it - its clock runs ahead of ours
        return $sentAtMs !== null && $sentAtMs - $receivedAtMs > self::SENT_AHEAD_MS;
    }
}
