<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Results\EventsPage\AgendaRow;

/**
 * The Community landing's three previews (docs/features/navigation README §4.9, COM-01):
 * - leaders: the top of the solo 500-piece leaderboard, with each row's shared rank;
 * - puzzlers: the most active puzzlers this month (the Players page spotlight's first list);
 * - events: the next public occurrences, at most one per series or one-time event.
 *
 * The same rows for every viewer except the players a signed-in viewer's blocks hide (blocklist); every guest gets the
 * same. The template adds one mark of the viewer's own: the star in front of their favourites' names
 * (_leaderboard_player, from the profile row).
 */
readonly final class CommunityLanding
{
    /**
     * @param list<SolvedPuzzle> $leaders
     * @param list<int> $leaderRanks the shared rank of each leader
     * @param list<SpotlightPerson> $puzzlers
     * @param list<AgendaRow> $events
     */
    public function __construct(
        public int $leaderboardPieces,
        public array $leaders,
        public array $leaderRanks,
        public array $puzzlers,
        public array $events,
    ) {
    }
}
