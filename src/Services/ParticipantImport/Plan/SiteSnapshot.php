<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport\Plan;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ParticipantImportRound;

/**
 * What the event looks like before an import - read once by ParticipantImportPlanner, never changed.
 */
readonly final class SiteSnapshot
{
    /**
     * @param list<ParticipantImportRound> $rounds ordered by starts_at, name, id
     * @param list<array{id: string, name: string, country: null|string, externalId: null|string, playerId: null|string, playerName: null|string, deletedAt: null|DateTimeImmutable, selfJoined: bool}> $participants
     *        every participant of the event, active first, then by id (today's matching order)
     * @param list<array{id: string, participantId: string, roundId: string, teamId: null|string}> $entries by id
     * @param array<string, array{id: string, roundId: string, name: null|string}> $teams team id => team, by id
     * @param array<string, array<string, true>> $results player id => round ids of this event the player has a result in
     *        (alone or as a member of a pair/team)
     * @param array<string, true> $existingPlayers lower-cased player ids of the file that exist
     * @param array<string, array<string, true>> $officialResults participant id => round ids of this event the participant
     *        holds official data in (a result or a qualified mark - their own, or their pair's/team's), see OfficialResultsGuard
     * @param array<string, true> $officialTeams team ids of this event with official data
     */
    public function __construct(
        public string $competitionId,
        public array $rounds,
        public array $participants,
        public array $entries,
        public array $teams,
        public array $results,
        public array $existingPlayers,
        public string $stateVersion,
        public array $officialResults = [],
        public array $officialTeams = [],
    ) {
    }

    /**
     * A result in the event (or the round) the import must never take away (D11): one the player added to their profile,
     * or an official result the organiser recorded for the participant.
     */
    public function hasAnyResult(null|string $playerId, null|string $participantId, null|string $roundId = null): bool
    {
        if ($this->hasResult($playerId, $roundId)) {
            return true;
        }

        if ($participantId === null || !isset($this->officialResults[$participantId])) {
            return false;
        }

        return $roundId === null || isset($this->officialResults[$participantId][$roundId]);
    }

    public function teamHasOfficialResult(string $teamId): bool
    {
        return isset($this->officialTeams[$teamId]);
    }

    public function hasResult(null|string $playerId, null|string $roundId = null): bool
    {
        if ($playerId === null || !isset($this->results[$playerId])) {
            return false;
        }

        return $roundId === null || isset($this->results[$playerId][$roundId]);
    }
}
