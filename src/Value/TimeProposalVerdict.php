<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How a competitor's tap reached the server (reliability hardening T2, TimeProposalTiming::judge(),
 * docs/features/competitions-management/competitor-pages.md "Timing you can trust"):
 * - on_time: received within 5 s of the tap
 * - offline: received later, and nothing the phone said contradicts the tap (it was offline, still confirming, or never
 *   got a heartbeat through) - nothing shown to the judge
 * - suspicious: a heartbeat of the same phone after the tap said it held no unsent tap, and the proposal came more than
 *   5 s after that heartbeat - "Check this time" with both times and "Use received" on the judge's card
 *
 * The recorded time is the tap's in every case (Jan). A proposal stored before T2 has none (null).
 */
enum TimeProposalVerdict: string
{
    case OnTime = 'on_time';
    case Offline = 'offline';
    case Suspicious = 'suspicious';
}
