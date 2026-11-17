<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport\Plan;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ParticipantImportRound;
use SpeedPuzzling\Web\Value\PlayerReference;

/**
 * What the event looks like before an import or a participants sheet change set - read once by SiteSnapshotReader,
 * never changed.
 *
 * @phpstan-type SnapshotParticipant array{id: string, name: string, country: null|string, externalId: null|string, playerId: null|string, playerName: null|string, deletedAt: null|DateTimeImmutable, selfJoined: bool, organizerNote: null|string, registrationStatus: null|string, connectedVia?: null|string, playerCode?: null|string, playerMasked?: bool}
 * @phpstan-type SnapshotEntry array{id: string, participantId: string, roundId: string, teamId: null|string, ownOfficialData: bool}
 * @phpstan-type SnapshotTeam array{id: string, roundId: string, name: null|string}
 * @phpstan-type SnapshotPlayer array{code: string, name: null|string, masked: bool}
 * @phpstan-type SnapshotReference array{status: string, playerId: null|string}
 */
readonly final class SiteSnapshot
{
    /** A player reference of the file (PlayerReference) names this player */
    public const string REFERENCE_FOUND = 'found';

    /** No player has (or had) that id / code */
    public const string REFERENCE_UNKNOWN = 'unknown';

    /** More than one player has held that code (player_code_change) - never guessed (R5) */
    public const string REFERENCE_CODE_CHANGED_OWNER = 'code_changed_owner';

    /**
     * @param list<ParticipantImportRound> $rounds ordered by starts_at, name, id
     * @param list<SnapshotParticipant> $participants every participant of the event, active first, then by id (today's matching order)
     * @param list<SnapshotEntry> $entries by id; `ownOfficialData` = the entry's own result or qualified mark (solo rounds)
     * @param array<string, SnapshotTeam> $teams team id => team, by id
     * @param array<string, array<string, true>> $results player id => round ids of this event the player has a result in
     *        (alone or as a member of a pair/team)
     * @param array<string, true> $existingPlayers lower-cased player ids asked about that exist
     * @param array<string, array<string, true>> $officialResults participant id => round ids of this event the participant
     *        holds official data in (a result or a qualified mark - their own, or their pair's/team's), see OfficialResultsGuard
     * @param array<string, true> $officialTeams team ids of this event with official data
     * @param array<string, SnapshotPlayer> $players lower-cased player id => the player a reference asked about resolved to;
     *        `masked` = the viewer may not see who it is (private to them, or blocked by them)
     * @param array<string, SnapshotReference> $references PlayerReference::key() => what it resolved to, by key
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
        public array $players = [],
        public array $references = [],
    ) {
    }

    /**
     * @return SnapshotReference what the reference resolved to (unknown when nobody asked about it)
     */
    public function resolve(PlayerReference $reference): array
    {
        return $this->references[$reference->key()] ?? ['status' => self::REFERENCE_UNKNOWN, 'playerId' => null];
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
