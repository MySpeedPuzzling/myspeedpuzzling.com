<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which round a competition display shows (D-displays-overlays.md §2 "Round"): the current one (running beats recent
 * beats upcoming - LiveResultsCurrentRound - optionally among a chosen subset of the event's rounds, for parallel halls)
 * or one pinned round. A pinned round that was deleted (pinned_round_id SET NULL) behaves as the current one.
 */
enum DisplayFollowMode: string
{
    case Current = 'current';
    case Pinned = 'pinned';
}
