<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How a competitor's tap reached the server (reliability hardening T2, TimeProposalTiming::judge(),
 * docs/features/competitions-management/competitor-pages.md "Timing you can trust"):
 * - on_time: received within 5 s of the tap - the judge's default is the tap time
 * - offline: received later, and nothing the phone said contradicts the tap (it was offline, still confirming, or never
 *   got a heartbeat through) - the tap time is the default, with a neutral note
 * - suspicious: a heartbeat of the same phone after the tap said it held no unsent tap, and the proposal came more than
 *   5 s after that heartbeat - "Check this time", the receipt time is the default
 *
 * A proposal stored before T2 has none (null) - the old rule applies (late → the receipt time).
 */
enum TimeProposalVerdict: string
{
    case OnTime = 'on_time';
    case Offline = 'offline';
    case Suspicious = 'suspicious';
}
