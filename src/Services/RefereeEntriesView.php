<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\RoundResultEntry;

/**
 * What the round's official records show of linked players to the one who opened them
 * (docs/features/competitions-management/live-results.md "Referees"): organisers see the participant list as they
 * recorded it, #codes included; a referee never sees the #code, profile name or id of a player who is private to
 * them - PrivateProfileAccess decides, so a private player who lets the referee see them stays visible.
 * The referees' live updates have no viewer and withhold every private player (OfficialResultsLiveUpdates).
 */
readonly final class RefereeEntriesView
{
    public function __construct(
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * @param list<RoundResultEntry> $entries
     * @return list<RoundResultEntry>
     */
    public function entries(array $entries, bool $organiser): array
    {
        if ($organiser) {
            return $entries;
        }

        $isRevealed = fn (string $playerId): bool => $this->privateProfileAccess->isRevealed($playerId);

        return array_map(static fn (RoundResultEntry $entry): RoundResultEntry => $entry->forReferee($isRevealed), $entries);
    }
}
