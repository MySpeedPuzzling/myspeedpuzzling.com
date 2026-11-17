<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where a competitor's time proposal stands (CompetitionTimeProposal, toolkit B-qr-competitor-page.md §5.2 with
 * R-review-and-plan.md §1.2):
 * - pending: waiting for a judge - one per round entry at most
 * - confirmed: a judge recorded the official result through it (the seconds may differ - the judge's verdict)
 * - rejected: a judge said no (not finished, wrong entry, other) - the competitor may propose again while the round runs
 * - superseded: a result recorded another way while it waited, or the round's stopwatch was reset - or, once
 *   confirmed, its result was cleared or changed by a judge afterwards (the one way out of `confirmed`)
 * - withdrawn: "Undo" by a linked member of the entry while it waited
 */
enum TimeProposalStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
    case Withdrawn = 'withdrawn';
}
