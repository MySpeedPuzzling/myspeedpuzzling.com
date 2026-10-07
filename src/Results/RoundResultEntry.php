<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use Closure;
use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * One round entry with its official record, for the organiser's tools (GetRoundResultEntries): a person of a solo
 * round (kind "person", CompetitionParticipantRound) or a pair/team (kind "team", CompetitionTeam) with its members.
 * `rank` is computed (OfficialResultsRanking), never stored - null for did not start and for no result yet.
 *
 * jsonSerialize() is the JSON shape every official results endpoint and Mercure update uses for an entry.
 */
readonly final class RoundResultEntry implements \JsonSerializable
{
    public const string KIND_PERSON = 'person';
    public const string KIND_TEAM = 'team';

    public function __construct(
        public RoundEntryRef $ref,
        /** self::KIND_PERSON | self::KIND_TEAM */
        public string $kind,
        public string $roundId,
        // Person: the participant's name; team: the team's name (null = unnamed)
        public null|string $name,
        // Person only
        public null|string $participantId,
        // Person: the participant's country; team: null (see $countries)
        public null|string $country,
        /** @var list<string> person: [country] when known; team: the members' countries, each once, in member order */
        public array $countries,
        /** @var list<RoundResultEntryMember> team members (active participants), empty for a person */
        public array $members,
        // Person: the linked MySpeedPuzzling player
        public null|string $playerId,
        public null|string $playerCode,
        public null|string $playerName,
        public null|int $tableNumber,
        public RoundEntryResult $result,
        public null|int $rank,
        public null|DateTimeImmutable $qualifiedAt,
        public null|DateTimeImmutable $resultEnteredAt,
        public null|string $resultEnteredById,
        public null|string $resultEnteredByName,
        // Person: the linked player's own private profile setting - never serialized, see forReferee()
        public bool $playerIsPrivate = false,
        // Person: forReferee() left the linked player out
        public bool $playerWithheld = false,
    ) {
    }

    /**
     * The entry as a referee may see it (docs/features/competitions-management/live-results.md "Referees"): a linked
     * player who is private to the referee - PrivateProfileAccess semantics, `$isRevealed` = their allow list -
     * keeps the organiser's name only, without their #code, profile name or id. Organisers get every entry as it is.
     *
     * @param Closure(string): bool $isRevealed
     */
    public function forReferee(Closure $isRevealed): self
    {
        $hidePlayer = $this->playerId !== null && $this->playerIsPrivate && $isRevealed($this->playerId) === false;
        $members = array_map(static fn (RoundResultEntryMember $member): RoundResultEntryMember => $member->forReferee($isRevealed), $this->members);

        if ($hidePlayer === false && $members === $this->members) {
            return $this;
        }

        return new self(
            ref: $this->ref,
            kind: $this->kind,
            roundId: $this->roundId,
            name: $this->name,
            participantId: $this->participantId,
            country: $this->country,
            countries: $this->countries,
            members: $members,
            playerId: $hidePlayer ? null : $this->playerId,
            playerCode: $hidePlayer ? null : $this->playerCode,
            playerName: $hidePlayer ? null : $this->playerName,
            tableNumber: $this->tableNumber,
            result: $this->result,
            rank: $this->rank,
            qualifiedAt: $this->qualifiedAt,
            resultEnteredAt: $this->resultEnteredAt,
            resultEnteredById: $this->resultEnteredById,
            resultEnteredByName: $this->resultEnteredByName,
            playerIsPrivate: $this->playerIsPrivate,
            playerWithheld: $hidePlayer,
        );
    }

    public function isQualified(): bool
    {
        return $this->qualifiedAt !== null;
    }

    /**
     * A name to show: the entry's name, else (an unnamed team) its members' names.
     */
    public function displayName(): string
    {
        if ($this->name !== null) {
            return $this->name;
        }

        return implode(', ', array_map(static fn (RoundResultEntryMember $member): string => $member->name, $this->members));
    }

    /**
     * Ids of the active participants the entry stands for - the person, or the team's members.
     *
     * @return list<string>
     */
    public function participantIds(): array
    {
        if ($this->participantId !== null) {
            return [$this->participantId];
        }

        return array_map(static fn (RoundResultEntryMember $member): string => $member->participantId, $this->members);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'ref' => $this->ref->toString(),
            'kind' => $this->kind,
            'id' => $this->ref->id,
            'roundId' => $this->roundId,
            'name' => $this->name,
            'displayName' => $this->displayName(),
            'participantId' => $this->participantId,
            'country' => $this->country,
            'countries' => $this->countries,
            'members' => $this->members,
            'playerId' => $this->playerId,
            'playerCode' => $this->playerCode,
            'playerName' => $this->playerName,
            // Only on what a referee gets - the organisers' JSON stays as it was
            ...($this->playerWithheld ? ['playerWithheld' => true] : []),
            'tableNumber' => $this->tableNumber,
            'result' => $this->result->toWire(),
            'rank' => $this->rank,
            'qualified' => $this->isQualified(),
            'qualifiedAt' => $this->qualifiedAt?->format(DateTimeImmutable::ATOM),
            'enteredAt' => $this->resultEnteredAt?->format(DateTimeImmutable::ATOM),
            'enteredBy' => $this->resultEnteredById === null ? null : [
                'playerId' => $this->resultEnteredById,
                'name' => $this->resultEnteredByName,
            ],
        ];
    }
}
