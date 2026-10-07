<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport\Plan;

use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * The rules both ways of changing an event's participants follow - the file import (PlanBuilder) and the participants
 * sheet (SheetChangesPlanner) - in one place, so a file and a sheet never disagree about the same change
 * (docs/features/competitions-management/participants-spreadsheet.md §6 "Implementation seam"). Pure functions over
 * what SiteSnapshotReader read.
 */
final class ParticipantRules
{
    /**
     * A player is linked to one active participant of an event at most - the participant holding them now, or null
     * when `$personKey` may take them. Removed participants keep their link and block nobody.
     *
     * @param iterable<string, array{playerId: null|string, deleted: bool, ...}> $people participant key => person
     */
    public static function playerLinkedTo(string $playerId, string $personKey, iterable $people): null|string
    {
        foreach ($people as $key => $person) {
            if ($key !== $personKey && $person['deleted'] === false && $person['playerId'] === $playerId) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The most common size of a round's pairs/teams - on a tie the smaller one, at least 2, 2 when there is none.
     * The import's "usual size" (D16 c) and the guess the round form is pre-filled with (D5).
     *
     * @param list<int> $sizes members of each pair/team that has any
     */
    public static function usualTeamSize(array $sizes): int
    {
        $sizes = array_values(array_filter($sizes, static fn (int $size): bool => $size > 0));

        if ($sizes === []) {
            return 2;
        }

        $counts = array_count_values($sizes);
        ksort($counts);
        $usual = (int) array_key_first($counts);
        foreach ($counts as $size => $count) {
            if ($count > $counts[$usual]) {
                $usual = $size;
            }
        }

        return max(2, $usual);
    }

    /**
     * How many people a pair/team of the round should have: 2 in a pair round, the organiser's setting in a team round
     * (CompetitionRound::$teamSize), else the most common size of its teams - null for a solo round.
     *
     * @param list<int> $sizes members of each pair/team of the round that has any
     */
    public static function expectedTeamSize(RoundCategory $category, null|int $storedSize, array $sizes): null|int
    {
        return match ($category) {
            RoundCategory::Solo => null,
            RoundCategory::Duo => 2,
            RoundCategory::Team => $storedSize ?? self::usualTeamSize($sizes),
        };
    }

    /**
     * An expected team size the organiser may set: none, or CompetitionRound::TEAM_SIZE_MIN..TEAM_SIZE_MAX people.
     */
    public static function isValidTeamSize(null|int $size): bool
    {
        return $size === null || ($size >= CompetitionRound::TEAM_SIZE_MIN && $size <= CompetitionRound::TEAM_SIZE_MAX);
    }

    /**
     * Official data a person holds in a round (official-results.md "Guards"): the result or qualified mark of their own
     * entry (solo rounds), or of the pair/team they are in.
     */
    public static function holdsOfficialData(SiteSnapshot $site, bool $entryHasOwnData, null|string $teamId): bool
    {
        return $entryHasOwnData || ($teamId !== null && $site->teamHasOfficialResult($teamId));
    }

    /**
     * Data a person must not be taken out of a round with (D11): official data (holdsOfficialData()), or a time the
     * linked player added to their profile in that round.
     */
    public static function holdsResultInRound(SiteSnapshot $site, null|string $playerId, string $roundId, bool $entryHasOwnData, null|string $teamId): bool
    {
        return self::holdsOfficialData($site, $entryHasOwnData, $teamId) || $site->hasResult($playerId, $roundId);
    }
}
