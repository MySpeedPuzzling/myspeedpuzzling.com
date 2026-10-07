<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One change of a participants sheet change set, as SheetChangesParser read it (every id a lower-case UUID). Field-level
 * and three-way: `from` is the value the page saw, `to` the value it wants - the server applies it only while the
 * value still is `from` (docs/features/competitions-management/participants-spreadsheet.md §6).
 *
 * A person's place in a round is one value (`place`): `out` (not in the round), `in` (in it without a pair/team) or
 * `team:<teamId>` (in that pair/team) - so "in the round?", "which team" and a move are one change with one `from`.
 */
readonly final class SheetChange
{
    public const string OUT = 'out';
    public const string IN = 'in';
    public const string TEAM_PREFIX = 'team:';

    private function __construct(
        public SheetChangeOp $op,
        // newParticipant / newTeam: the new row's id - the page's own, so the same change sent twice creates nothing twice
        public null|string $id = null,
        public null|string $participantId = null,
        public null|string $roundId = null,
        public null|string $teamId = null,
        public null|SheetChangeField $field = null,
        public null|string|int $from = null,
        public null|string|int $to = null,
        // newParticipant / newTeam
        public null|string $name = null,
        public null|string $country = null,
        public null|string $externalId = null,
    ) {
    }

    public static function newParticipant(string $id, string $name, null|string $country, null|string $externalId): self
    {
        return new self(SheetChangeOp::NewParticipant, id: $id, name: $name, country: $country, externalId: $externalId);
    }

    public static function field(string $participantId, SheetChangeField $field, null|string $from, null|string $to): self
    {
        return new self(SheetChangeOp::Field, participantId: $participantId, field: $field, from: $from, to: $to);
    }

    public static function player(string $participantId, null|string $from, null|string $to): self
    {
        return new self(SheetChangeOp::Player, participantId: $participantId, from: $from, to: $to);
    }

    public static function place(string $participantId, string $roundId, string $from, string $to): self
    {
        return new self(SheetChangeOp::Place, participantId: $participantId, roundId: $roundId, from: $from, to: $to);
    }

    public static function newTeam(string $id, string $roundId, null|string $name): self
    {
        return new self(SheetChangeOp::NewTeam, id: $id, roundId: $roundId, name: $name);
    }

    public static function renameTeam(string $teamId, null|string $from, null|string $to): self
    {
        return new self(SheetChangeOp::RenameTeam, teamId: $teamId, from: $from, to: $to);
    }

    public static function deleteTeam(string $teamId): self
    {
        return new self(SheetChangeOp::DeleteTeam, teamId: $teamId);
    }

    public static function remove(string $participantId): self
    {
        return new self(SheetChangeOp::Remove, participantId: $participantId);
    }

    public static function restore(string $participantId): self
    {
        return new self(SheetChangeOp::Restore, participantId: $participantId);
    }

    public static function teamSize(string $roundId, null|int $from, null|int $to): self
    {
        return new self(SheetChangeOp::TeamSize, roundId: $roundId, from: $from, to: $to);
    }

    public static function teamPlace(string $teamId): string
    {
        return self::TEAM_PREFIX . $teamId;
    }

    /**
     * The pair/team a place names, null for `out` / `in`.
     */
    public static function teamOfPlace(string $place): null|string
    {
        return str_starts_with($place, self::TEAM_PREFIX) ? substr($place, strlen(self::TEAM_PREFIX)) : null;
    }
}
