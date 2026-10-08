<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantsSheet\Plan;

use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Results\SheetChangeOutcome;
use SpeedPuzzling\Web\Results\SheetGroupOutcome;
use SpeedPuzzling\Web\Results\SheetWarning;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantImportOperations;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantNameKey;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantRules;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\SiteSnapshot;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\SheetChange;
use SpeedPuzzling\Web\Value\SheetChangeField;
use SpeedPuzzling\Web\Value\SheetChangeGroup;
use SpeedPuzzling\Web\Value\SheetChangeOp;
use SpeedPuzzling\Web\Value\SheetChangeStatus;

/**
 * One planning run of SheetChangesPlanner: the groups of a change set evaluated in order against a working copy of the
 * event - nothing is written (docs/features/competitions-management/participants-spreadsheet.md §5, §6, delivery
 * contract §3.1).
 *
 * - Every change is checked three-way against the working state (what the earlier groups and the earlier changes of
 *   its own group left): the value is `to` already → unchanged; it is `from` → applied; else → conflict.
 * - A group is all or nothing: one conflict or refusal and the working state goes back to what it was before the
 *   group; the changes that went through are reported `skipped`. Groups are independent of each other.
 * - The rules are the import's (ParticipantRules, SiteSnapshot): a player linked to one active participant at most, an
 *   external id one participant's, no person taken out of a round - or the event - while they hold a result there
 *   (their own entry's official data, or a time their player - linked now or when the change set started - added to
 *   their profile), no pair/team with official data deleted.
 * - A pair's/team's result belongs to its line-up: a member may leave it (warning `team_result_line_up_changed`) while
 *   it keeps at least one member taking part - not removed, not on the waitlist of a managed event; a group taking the
 *   last one away is refused (`team_has_result`).
 * - A pair/team without a name and without official data that a group empties (at least one active member before the
 *   group, none after, not by removing people) is deleted with it - a named one stays as a team made in advance. A
 *   pair/team the change set creates and leaves without a name and without anybody is never created.
 * - At the end the NET difference between the event before and after all groups becomes the operations: a person taken
 *   out of a round and put back is no change at all, never a delete and an insert of the same entry.
 *
 * The working state is plain arrays on purpose: saving and restoring it around a group is an assignment (copy on
 * write), whatever the group did.
 *
 * @phpstan-type Person array{name: string, country: null|string, externalId: null|string, playerId: null|string, note: null|string, deleted: bool, selfJoined: bool, markAsImported: bool, waitlisted: bool, new: bool}
 * @phpstan-type Entry array{id: null|string, present: bool, team: null|string, ownData: bool}
 * @phpstan-type Team array{roundId: string, name: null|string, new: bool, deleted: bool}
 * @phpstan-type Round array{name: string, category: RoundCategory, teamSize: null|int}
 * @phpstan-type State array{people: array<string, Person>, entries: array<string, array<string, Entry>>, teams: array<string, Team>, members: array<string, array<string, true>>, rounds: array<string, Round>, nameKeys: array<string, string>}
 */
final class SheetPlanRun
{
    public const int NAME_MAX_LENGTH = 255;
    public const int EXTERNAL_ID_MAX_LENGTH = 255;

    /** SheetChangeOutcome::$cause - the linked player's own time keeps the person (has_result_in_round/_event) */
    public const string CAUSE_OWN_TIME = 'own_time';

    /** SheetChangeOutcome::$cause - a pair/team with a result would be left without anybody (team_has_result) */
    public const string CAUSE_EMPTIED = 'emptied';

    /** SheetChangeOutcome::$cause - only people on the waitlist would be left in a pair/team with a result */
    public const string CAUSE_WAITLISTED_ONLY = 'waitlisted_only';

    /** @var array<string, Person> participant id => person, every participant of the event incl. removed ones */
    private array $people = [];

    /** @var array<string, array<string, Entry>> participant id => round id => their entry (one per person and round) */
    private array $entries = [];

    /** @var array<string, Team> */
    private array $teams = [];

    /** @var array<string, array<string, true>> team id => participant ids whose present entry is in it (removed people too) */
    private array $members = [];

    /** @var array<string, Round> */
    private array $rounds = [];

    /** @var array<string, string> participant id => ParticipantNameKey */
    private array $nameKeys = [];

    /** @var State the event before the change set */
    private readonly array $start;

    /** @var array<string, int> team id => active members when the current group started (recorded on its first touch) */
    private array $activeAtGroupStart = [];

    /** @var array<string, int> team id => members taking part (goingMembers()) when the current group started */
    private array $goingAtGroupStart = [];

    /** @var array<string, int> team id => index of the change of the current group that last took a member out of it */
    private array $lostMember = [];

    /** @var array<string, true> teams whose members the current group changed */
    private array $touchedTeams = [];

    /** @var array<string, true> people the current group removed from the event */
    private array $removedInGroup = [];

    /** @var array<string, SheetWarning> the current group's warnings, one per code and subject */
    private array $warnings = [];

    /**
     * @param array<string, true> $foreignParticipantIds ids of new participants that are participants of other events
     * @param array<string, true> $foreignTeamIds team ids the changes name that are pairs/teams of other events
     */
    public function __construct(
        private readonly SiteSnapshot $site,
        private readonly bool $registrationManaged,
        private readonly array $foreignParticipantIds,
        private readonly array $foreignTeamIds,
    ) {
        foreach ($site->rounds as $round) {
            $this->rounds[$round->id] = ['name' => $round->name, 'category' => $round->category, 'teamSize' => $round->teamSize];
        }

        foreach ($site->participants as $participant) {
            $this->people[$participant['id']] = [
                'name' => $participant['name'],
                'country' => $participant['country'],
                'externalId' => $participant['externalId'],
                'playerId' => $participant['playerId'],
                'note' => $participant['organizerNote'],
                'deleted' => $participant['deletedAt'] !== null,
                'selfJoined' => $participant['selfJoined'],
                'markAsImported' => false,
                'waitlisted' => $participant['registrationStatus'] === RegistrationStatus::Waitlisted->value,
                'new' => false,
            ];
            $this->nameKeys[$participant['id']] = ParticipantNameKey::of($participant['name']);
        }

        foreach ($site->teams as $team) {
            $this->teams[$team['id']] = ['roundId' => $team['roundId'], 'name' => $team['name'], 'new' => false, 'deleted' => false];
            $this->members[$team['id']] = [];
        }

        foreach ($site->entries as $entry) {
            $this->entries[$entry['participantId']][$entry['roundId']] = [
                'id' => $entry['id'],
                'present' => true,
                'team' => $entry['teamId'],
                'ownData' => $entry['ownOfficialData'],
            ];

            if ($entry['teamId'] !== null) {
                $this->members[$entry['teamId']][$entry['participantId']] = true;
            }
        }

        $this->start = $this->state();
    }

    /**
     * @param list<SheetChangeGroup> $groups
     */
    public function run(array $groups): SheetPlan
    {
        $outcomes = [];

        foreach ($groups as $group) {
            $outcomes[] = $this->group($group);
        }

        return new SheetPlan($outcomes, $this->operations(), $this->teamSizes());
    }

    private function group(SheetChangeGroup $group): SheetGroupOutcome
    {
        $before = $this->state();
        $this->activeAtGroupStart = [];
        $this->goingAtGroupStart = [];
        $this->lostMember = [];
        $this->touchedTeams = [];
        $this->removedInGroup = [];
        $this->warnings = [];

        $outcomes = [];
        foreach ($group->changes as $index => $change) {
            $outcomes[$index] = $this->change($change, $index);
        }

        $deletedTeams = [];

        if (!self::failed($outcomes)) {
            // Pairs/teams the group took people out of
            foreach ($this->lostMember as $teamId => $index) {
                $team = $this->teams[$teamId];

                if ($team['deleted']) {
                    continue;
                }

                $activeBefore = $this->activeAtGroupStart[$teamId] ?? 0;
                $active = $this->activeMembers($teamId);

                // A result belongs to its line-up: one with official data keeps somebody who takes part - the group took
                // the last one away (only people on the waitlist left, or nobody at all) → refused
                if ($this->site->teamHasOfficialResult($teamId)) {
                    $emptied = $activeBefore > 0 && $active === 0;
                    $lastGoingLeft = ($this->goingAtGroupStart[$teamId] ?? 0) > 0 && $this->goingMembers($teamId) === 0;

                    if ($emptied || $lastGoingLeft) {
                        $outcomes[$index] = $this->refused($group->changes[$index], $index, 'team_has_result', [
                            'team' => $this->teamLabel($teamId),
                            'round' => $this->rounds[$team['roundId']]['name'],
                        ], $active > 0 ? self::CAUSE_WAITLISTED_ONLY : self::CAUSE_EMPTIED);
                    }

                    continue;
                }

                if ($activeBefore === 0 || $active > 0) {
                    continue;
                }

                // Emptied (people before the group, none after it). Named = made in advance, it stays; emptied by
                // removing people from the event, it stays too (D10)
                if ($team['name'] === null && !$this->emptiedByRemoval($teamId)) {
                    $this->deleteTeam($teamId);
                    $deletedTeams[] = $teamId;
                }
            }
        }

        if (self::failed($outcomes)) {
            $this->restore($before);

            $status = SheetChangeStatus::Refused;
            foreach ($outcomes as $index => $outcome) {
                if ($outcome->status === SheetChangeStatus::Conflict) {
                    $status = SheetChangeStatus::Conflict;
                }

                $outcomes[$index] = new SheetChangeOutcome(
                    $outcome->index,
                    $outcome->status === SheetChangeStatus::Applied ? SheetChangeStatus::Skipped : $outcome->status,
                    $outcome->reason,
                    // What the server holds - the group is not applied, so what was there before it
                    $this->current($group->changes[$index]),
                    $outcome->parameters,
                    $outcome->cause,
                );
            }

            return new SheetGroupOutcome($group->id, $status, array_values($outcomes));
        }

        $this->warnAboutTeamSizes();

        $applied = array_filter($outcomes, static fn (SheetChangeOutcome $outcome): bool => $outcome->status === SheetChangeStatus::Applied);

        return new SheetGroupOutcome(
            $group->id,
            $applied !== [] ? SheetChangeStatus::Applied : SheetChangeStatus::Unchanged,
            array_values($outcomes),
            array_values($this->warnings),
            $deletedTeams,
        );
    }

    /**
     * @param array<int, SheetChangeOutcome> $outcomes
     */
    private static function failed(array $outcomes): bool
    {
        foreach ($outcomes as $outcome) {
            if ($outcome->status === SheetChangeStatus::Conflict || $outcome->status === SheetChangeStatus::Refused) {
                return true;
            }
        }

        return false;
    }

    /**
     * Applies the change to the working state when it goes through.
     *
     * @phpstan-impure
     */
    private function change(SheetChange $change, int $index): SheetChangeOutcome
    {
        return match ($change->op) {
            SheetChangeOp::NewParticipant => $this->newParticipant($change, $index),
            SheetChangeOp::Field => $this->field($change, $index),
            SheetChangeOp::Player => $this->player($change, $index),
            SheetChangeOp::Place => $this->place($change, $index),
            SheetChangeOp::NewTeam => $this->newTeam($change, $index),
            SheetChangeOp::RenameTeam => $this->renameTeam($change, $index),
            SheetChangeOp::DeleteTeam => $this->deleteTeamChange($change, $index),
            SheetChangeOp::Remove => $this->remove($change, $index),
            SheetChangeOp::Restore => $this->restoreParticipant($change, $index),
            SheetChangeOp::TeamSize => $this->teamSize($change, $index),
        };
    }

    private function newParticipant(SheetChange $change, int $index): SheetChangeOutcome
    {
        $id = (string) $change->id;

        // Created before (a resend whose answer got lost) - or one of this event anyway
        if (isset($this->people[$id])) {
            return $this->outcome($change, $index, SheetChangeStatus::Unchanged);
        }

        // The page's id belongs to a participant of another event - never taken over
        if (isset($this->foreignParticipantIds[$id])) {
            return $this->refused($change, $index, 'id_taken');
        }

        $name = self::cleanName($change->name);

        if ($name === null) {
            return $this->refused($change, $index, 'name_blank');
        }

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            return $this->refused($change, $index, 'name_too_long', ['max' => self::NAME_MAX_LENGTH]);
        }

        $country = self::country($change->country);

        if ($country === false) {
            return $this->refused($change, $index, 'invalid_country', ['country' => (string) $change->country]);
        }

        $externalId = self::cleanText($change->externalId);

        if ($externalId !== null && mb_strlen($externalId) > self::EXTERNAL_ID_MAX_LENGTH) {
            return $this->refused($change, $index, 'external_id_too_long', ['max' => self::EXTERNAL_ID_MAX_LENGTH]);
        }

        if ($externalId !== null && ($taken = $this->externalIdTaken($externalId, $id, $change, $index)) !== null) {
            return $taken;
        }

        $this->people[$id] = [
            'name' => $name,
            'country' => $country,
            'externalId' => $externalId,
            'playerId' => null,
            'note' => null,
            'deleted' => false,
            'selfJoined' => false,
            'markAsImported' => false,
            'waitlisted' => false,
            'new' => true,
        ];
        $nameKey = ParticipantNameKey::of($name);
        $this->nameKeys[$id] = $nameKey;

        // D17: the same name written another way - probably the same person, never merged by itself
        foreach ($this->nameKeys as $otherId => $otherKey) {
            if ($otherId !== $id && $otherKey === $nameKey && $this->people[$otherId]['deleted'] === false) {
                $this->warn(new SheetWarning(SheetWarning::SAME_NAME_AS_EXISTING, participantId: $id, parameters: [
                    'name' => $name,
                    'other' => $this->people[$otherId]['name'],
                ]));

                break;
            }
        }

        return $this->outcome($change, $index, SheetChangeStatus::Applied);
    }

    private function field(SheetChange $change, int $index): SheetChangeOutcome
    {
        $id = (string) $change->participantId;
        $person = $this->people[$id] ?? null;
        $field = $change->field;
        assert($field !== null);

        if ($person === null) {
            return $this->refused($change, $index, 'participant_not_found');
        }

        if ($person['deleted']) {
            return $this->refused($change, $index, 'participant_removed', ['name' => $person['name']]);
        }

        $to = is_string($change->to) ? $change->to : null;
        $from = is_string($change->from) ? $change->from : null;
        $current = null;

        switch ($field) {
            case SheetChangeField::Name:
                $to = self::cleanName($to);

                if ($to === null) {
                    return $this->refused($change, $index, 'name_blank');
                }

                if (mb_strlen($to) > self::NAME_MAX_LENGTH) {
                    return $this->refused($change, $index, 'name_too_long', ['max' => self::NAME_MAX_LENGTH]);
                }

                $current = self::cleanName($person['name']);
                $from = self::cleanName($from);
                break;

            case SheetChangeField::Country:
                $country = self::country($to);

                if ($country === false) {
                    return $this->refused($change, $index, 'invalid_country', ['country' => (string) $to]);
                }

                $to = $country;
                // Stored as the CountryCode case name (lower case) - an older upper-case value is the same country
                $current = $person['country'] !== null ? strtolower($person['country']) : null;
                $from = self::cleanText($from) !== null ? strtolower((string) self::cleanText($from)) : null;
                break;

            case SheetChangeField::ExternalId:
                $to = self::cleanText($to);

                if ($to !== null && mb_strlen($to) > self::EXTERNAL_ID_MAX_LENGTH) {
                    return $this->refused($change, $index, 'external_id_too_long', ['max' => self::EXTERNAL_ID_MAX_LENGTH]);
                }

                $current = self::cleanText($person['externalId']);
                $from = self::cleanText($from);
                break;

            case SheetChangeField::Note:
                $to = self::cleanText($to);

                if ($to !== null && mb_strlen($to) > CompetitionParticipant::ORGANIZER_NOTE_MAX_LENGTH) {
                    return $this->refused($change, $index, 'note_too_long', ['max' => CompetitionParticipant::ORGANIZER_NOTE_MAX_LENGTH]);
                }

                $current = self::cleanText($person['note']);
                $from = self::cleanText($from);
                break;
        }

        if ($current === $to) {
            return $this->outcome($change, $index, SheetChangeStatus::Unchanged);
        }

        if ($current !== $from) {
            return $this->outcome($change, $index, SheetChangeStatus::Conflict, 'changed_meanwhile');
        }

        if ($field === SheetChangeField::ExternalId && $to !== null && ($taken = $this->externalIdTaken($to, $id, $change, $index)) !== null) {
            return $taken;
        }

        match ($field) {
            SheetChangeField::Name => $person['name'] = (string) $to,
            SheetChangeField::Country => $person['country'] = $to,
            SheetChangeField::ExternalId => $person['externalId'] = $to,
            SheetChangeField::Note => $person['note'] = $to,
        };

        $this->people[$id] = $person;
        $this->nameKeys[$id] = ParticipantNameKey::of($person['name']);

        return $this->outcome($change, $index, SheetChangeStatus::Applied);
    }

    private function player(SheetChange $change, int $index): SheetChangeOutcome
    {
        $id = (string) $change->participantId;
        $person = $this->people[$id] ?? null;

        if ($person === null) {
            return $this->refused($change, $index, 'participant_not_found');
        }

        if ($person['deleted']) {
            return $this->refused($change, $index, 'participant_removed', ['name' => $person['name']]);
        }

        $to = is_string($change->to) ? $change->to : null;

        if ($person['playerId'] === $to) {
            return $this->outcome($change, $index, SheetChangeStatus::Unchanged);
        }

        if ($person['playerId'] !== $change->from) {
            return $this->outcome($change, $index, SheetChangeStatus::Conflict, 'changed_meanwhile');
        }

        if ($to !== null) {
            if (!isset($this->site->existingPlayers[$to])) {
                return $this->refused($change, $index, 'player_not_found');
            }

            // The import's rule: a player is linked to one active participant of the event at most
            $other = ParticipantRules::playerLinkedTo($to, $id, $this->people);

            if ($other !== null) {
                return $this->refused($change, $index, 'player_linked_elsewhere', [
                    'name' => $person['name'],
                    'other' => $this->people[$other]['name'],
                ]);
            }
        }

        $person['playerId'] = $to;
        $this->people[$id] = $person;

        return $this->outcome($change, $index, SheetChangeStatus::Applied);
    }

    private function place(SheetChange $change, int $index): SheetChangeOutcome
    {
        $id = (string) $change->participantId;
        $roundId = (string) $change->roundId;
        $person = $this->people[$id] ?? null;
        $round = $this->rounds[$roundId] ?? null;
        $from = (string) $change->from;
        $to = (string) $change->to;
        $toTeam = SheetChange::teamOfPlace($to);

        if ($person === null) {
            return $this->refused($change, $index, 'participant_not_found');
        }

        if ($person['deleted']) {
            return $this->refused($change, $index, 'participant_removed', ['name' => $person['name']]);
        }

        if ($round === null) {
            return $this->refused($change, $index, 'round_not_found');
        }

        if ($round['category'] === RoundCategory::Solo && ($toTeam !== null || SheetChange::teamOfPlace($from) !== null)) {
            return $this->refused($change, $index, 'not_a_team_round', ['round' => $round['name']]);
        }

        if ($toTeam !== null) {
            $team = $this->teams[$toTeam] ?? null;

            if ($team === null || $team['deleted']) {
                return $this->refused($change, $index, 'team_not_found');
            }

            if ($team['roundId'] !== $roundId) {
                return $this->refused($change, $index, 'team_of_another_round', [
                    'team' => $this->teamLabel($toTeam),
                    'round' => $this->rounds[$team['roundId']]['name'],
                ]);
            }
        }

        $current = $this->placeOf($id, $roundId);

        if ($current === $to) {
            return $this->outcome($change, $index, SheetChangeStatus::Unchanged);
        }

        if ($current !== $from) {
            return $this->outcome($change, $index, SheetChangeStatus::Conflict, 'changed_meanwhile');
        }

        // Their own result keeps them in the round; a pair's/team's result does not - a member may leave while the
        // pair/team keeps somebody (the emptying guard at the end of the group)
        $keptBy = $to === SheetChange::OUT ? $this->keptInRoundBy($id, $roundId) : null;

        if ($keptBy !== null) {
            return $this->refused($change, $index, 'has_result_in_round', [
                'name' => $person['name'],
                'round' => $round['name'],
            ], self::cause($keptBy));
        }

        $currentTeam = SheetChange::teamOfPlace($current);
        $this->setPlace($id, $roundId, $to, $index);

        // The result of a pair/team is its line-up's: who joins or leaves one with official data changes whose it is
        foreach (array_filter([$currentTeam, $toTeam]) as $teamId) {
            if ($this->site->teamHasOfficialResult($teamId)) {
                $this->warn(new SheetWarning(SheetWarning::TEAM_RESULT_LINE_UP_CHANGED, participantId: $id, teamId: $teamId, roundId: $roundId, parameters: [
                    'team' => $this->teamLabel($teamId),
                    'round' => $round['name'],
                ]));
            }
        }

        if ($to !== SheetChange::OUT && $person['waitlisted']) {
            $this->warn(new SheetWarning(SheetWarning::WAITLISTED_MEMBER, participantId: $id, roundId: $roundId, parameters: [
                'name' => $person['name'],
                'round' => $round['name'],
            ]));
        }

        return $this->outcome($change, $index, SheetChangeStatus::Applied);
    }

    private function newTeam(SheetChange $change, int $index): SheetChangeOutcome
    {
        $id = (string) $change->id;
        $roundId = (string) $change->roundId;
        $round = $this->rounds[$roundId] ?? null;

        if ($round === null) {
            return $this->refused($change, $index, 'round_not_found');
        }

        if ($round['category'] === RoundCategory::Solo) {
            return $this->refused($change, $index, 'not_a_team_round', ['round' => $round['name']]);
        }

        $existing = $this->teams[$id] ?? null;

        if ($existing !== null) {
            // Created before (a resend) - any other use of the id is somebody else's team
            return $existing['deleted'] === false && $existing['roundId'] === $roundId
                ? $this->outcome($change, $index, SheetChangeStatus::Unchanged)
                : $this->refused($change, $index, 'id_taken');
        }

        if (isset($this->foreignTeamIds[$id])) {
            return $this->refused($change, $index, 'id_taken');
        }

        $name = CompetitionTeam::cleanName($change->name);

        if ($name !== null && mb_strlen($name) > CompetitionTeam::NAME_MAX_LENGTH) {
            return $this->refused($change, $index, 'team_name_too_long', ['max' => CompetitionTeam::NAME_MAX_LENGTH]);
        }

        $this->teams[$id] = ['roundId' => $roundId, 'name' => $name, 'new' => true, 'deleted' => false];
        $this->members[$id] = [];
        $this->touchedTeams[$id] = true;
        $this->warnAboutSharedName($id);

        return $this->outcome($change, $index, SheetChangeStatus::Applied);
    }

    private function renameTeam(SheetChange $change, int $index): SheetChangeOutcome
    {
        $id = (string) $change->teamId;
        $team = $this->teams[$id] ?? null;

        if ($team === null || $team['deleted']) {
            return $this->refused($change, $index, 'team_not_found');
        }

        $round = $this->rounds[$team['roundId']];

        if ($round['category'] === RoundCategory::Solo) {
            return $this->refused($change, $index, 'not_a_team_round', ['round' => $round['name']]);
        }

        $to = CompetitionTeam::cleanName(is_string($change->to) ? $change->to : null);

        if ($to !== null && mb_strlen($to) > CompetitionTeam::NAME_MAX_LENGTH) {
            return $this->refused($change, $index, 'team_name_too_long', ['max' => CompetitionTeam::NAME_MAX_LENGTH]);
        }

        $current = CompetitionTeam::cleanName($team['name']);

        if ($current === $to) {
            return $this->outcome($change, $index, SheetChangeStatus::Unchanged);
        }

        if ($current !== CompetitionTeam::cleanName(is_string($change->from) ? $change->from : null)) {
            return $this->outcome($change, $index, SheetChangeStatus::Conflict, 'changed_meanwhile');
        }

        $team['name'] = $to;
        $this->teams[$id] = $team;
        $this->warnAboutSharedName($id);

        return $this->outcome($change, $index, SheetChangeStatus::Applied);
    }

    private function deleteTeamChange(SheetChange $change, int $index): SheetChangeOutcome
    {
        $id = (string) $change->teamId;
        $team = $this->teams[$id] ?? null;

        if ($team === null) {
            // Never there, or deleted already (a resend, another organiser) - but never one of another event
            return isset($this->foreignTeamIds[$id])
                ? $this->refused($change, $index, 'team_not_found')
                : $this->outcome($change, $index, SheetChangeStatus::Unchanged);
        }

        if ($team['deleted']) {
            return $this->outcome($change, $index, SheetChangeStatus::Unchanged);
        }

        $round = $this->rounds[$team['roundId']];

        if ($round['category'] === RoundCategory::Solo) {
            return $this->refused($change, $index, 'not_a_team_round', ['round' => $round['name']]);
        }

        if ($this->site->teamHasOfficialResult($id)) {
            return $this->refused($change, $index, 'team_has_result', [
                'team' => $this->teamLabel($id),
                'round' => $round['name'],
            ]);
        }

        $this->deleteTeam($id);

        return $this->outcome($change, $index, SheetChangeStatus::Applied);
    }

    private function remove(SheetChange $change, int $index): SheetChangeOutcome
    {
        $id = (string) $change->participantId;
        $person = $this->people[$id] ?? null;

        if ($person === null) {
            return $this->refused($change, $index, 'participant_not_found');
        }

        if ($person['deleted']) {
            return $this->outcome($change, $index, SheetChangeStatus::Unchanged);
        }

        $keptBy = $this->keptInEventBy($id);

        if ($keptBy !== null) {
            return $this->refused($change, $index, 'has_result_in_event', [
                'name' => $person['name'],
                'round' => $this->rounds[$keptBy['roundId']]['name'],
            ], self::cause($keptBy['by']));
        }

        $this->recordTeamsOf($id);
        $person['deleted'] = true;
        // A self-joined row is made the organiser's first: removed, it would be the player's own "I left" record, which
        // "I'm going" silently restores (import rule D10)
        $person['markAsImported'] = $person['markAsImported'] || $person['selfJoined'];
        $this->people[$id] = $person;
        $this->removedInGroup[$id] = true;

        return $this->outcome($change, $index, SheetChangeStatus::Applied);
    }

    private function restoreParticipant(SheetChange $change, int $index): SheetChangeOutcome
    {
        $id = (string) $change->participantId;
        $person = $this->people[$id] ?? null;

        if ($person === null) {
            return $this->refused($change, $index, 'participant_not_found');
        }

        if ($person['deleted'] === false) {
            return $this->outcome($change, $index, SheetChangeStatus::Unchanged);
        }

        // Their player may be linked to another active participant by now - one active link at most
        if ($person['playerId'] !== null) {
            $other = ParticipantRules::playerLinkedTo($person['playerId'], $id, $this->people);

            if ($other !== null) {
                return $this->refused($change, $index, 'player_linked_elsewhere', [
                    'name' => $person['name'],
                    'other' => $this->people[$other]['name'],
                ]);
            }
        }

        $this->recordTeamsOf($id);
        $person['deleted'] = false;
        // No waitlist on an event that does not manage registration (RestoreCompetitionParticipantHandler)
        $person['waitlisted'] = $person['waitlisted'] && $this->registrationManaged;
        $this->people[$id] = $person;

        return $this->outcome($change, $index, SheetChangeStatus::Applied);
    }

    private function teamSize(SheetChange $change, int $index): SheetChangeOutcome
    {
        $roundId = (string) $change->roundId;
        $round = $this->rounds[$roundId] ?? null;

        if ($round === null) {
            return $this->refused($change, $index, 'round_not_found');
        }

        if ($round['category'] !== RoundCategory::Team) {
            return $this->refused($change, $index, 'not_a_team_round', ['round' => $round['name']]);
        }

        $to = is_int($change->to) ? $change->to : null;

        if (ParticipantRules::isValidTeamSize($to) === false) {
            return $this->refused($change, $index, 'invalid_team_size', ['min' => CompetitionRound::TEAM_SIZE_MIN, 'max' => CompetitionRound::TEAM_SIZE_MAX]);
        }

        if ($round['teamSize'] === $to) {
            return $this->outcome($change, $index, SheetChangeStatus::Unchanged);
        }

        if ($round['teamSize'] !== $change->from) {
            return $this->outcome($change, $index, SheetChangeStatus::Conflict, 'changed_meanwhile');
        }

        $round['teamSize'] = $to;
        $this->rounds[$roundId] = $round;

        return $this->outcome($change, $index, SheetChangeStatus::Applied);
    }

    /**
     * `out`, `in` or `team:<id>` - a person's place in a round as the working state has it.
     */
    private function placeOf(string $participantId, string $roundId): string
    {
        $entry = $this->entries[$participantId][$roundId] ?? null;

        if ($entry === null || $entry['present'] === false) {
            return SheetChange::OUT;
        }

        return $entry['team'] !== null ? SheetChange::teamPlace($entry['team']) : SheetChange::IN;
    }

    private function setPlace(string $participantId, string $roundId, string $place, int $index): void
    {
        $entry = $this->entries[$participantId][$roundId] ?? null;
        $oldTeam = $entry !== null && $entry['present'] ? $entry['team'] : null;
        $newTeam = SheetChange::teamOfPlace($place);

        if ($oldTeam !== null) {
            $this->recordActive($oldTeam);
            unset($this->members[$oldTeam][$participantId]);
            $this->lostMember[$oldTeam] = $index;
        }

        if ($newTeam !== null) {
            $this->recordActive($newTeam);
            $this->members[$newTeam][$participantId] = true;
        }

        if ($place === SheetChange::OUT) {
            if ($entry !== null) {
                $entry['present'] = false;
                $entry['team'] = null;
                $this->entries[$participantId][$roundId] = $entry;
            }

            return;
        }

        // Put back into a round they were in before the change set: the same entry again, never a new one next to it
        $this->entries[$participantId][$roundId] = [
            'id' => $entry['id'] ?? null,
            'present' => true,
            'team' => $newTeam,
            'ownData' => $entry['ownData'] ?? false,
        ];
    }

    /**
     * Members keep their place in the round, without a pair/team - also removed people still pointing at it (WEB-D5).
     */
    private function deleteTeam(string $teamId): void
    {
        $team = $this->teams[$teamId];

        foreach (array_keys($this->members[$teamId] ?? []) as $participantId) {
            $entry = $this->entries[$participantId][$team['roundId']];
            $entry['team'] = null;
            $this->entries[$participantId][$team['roundId']] = $entry;
        }

        $team['deleted'] = true;
        $this->teams[$teamId] = $team;
        $this->members[$teamId] = [];
        unset($this->touchedTeams[$teamId]);
    }

    private function recordActive(string $teamId): void
    {
        $this->activeAtGroupStart[$teamId] ??= $this->activeMembers($teamId);
        $this->goingAtGroupStart[$teamId] ??= $this->goingMembers($teamId);
        $this->touchedTeams[$teamId] = true;
    }

    private function recordTeamsOf(string $participantId): void
    {
        foreach ($this->entries[$participantId] ?? [] as $entry) {
            if ($entry['present'] && $entry['team'] !== null) {
                $this->recordActive($entry['team']);
            }
        }
    }

    private function activeMembers(string $teamId): int
    {
        $count = 0;

        foreach (array_keys($this->members[$teamId] ?? []) as $participantId) {
            if ($this->people[$participantId]['deleted'] === false) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Members taking part: not removed, and not on the waitlist of an event that manages registration (they are placed
     * for when they get a spot - the results tools leave them out, O8).
     */
    private function goingMembers(string $teamId): int
    {
        $count = 0;

        foreach (array_keys($this->members[$teamId] ?? []) as $participantId) {
            $person = $this->people[$participantId];

            if ($person['deleted'] === false && ($person['waitlisted'] === false || $this->registrationManaged === false)) {
                $count++;
            }
        }

        return $count;
    }

    private function emptiedByRemoval(string $teamId): bool
    {
        foreach (array_keys($this->members[$teamId] ?? []) as $participantId) {
            if (isset($this->removedInGroup[$participantId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * What keeps the person in the round (ParticipantRules::keptInRoundBy()): their player's own time there - of the
     * player linked now and of the one linked when the change set started, so unlinking first in the same change set
     * does not get around it (an unlink saved earlier does: the time is not theirs any more) - or their own entry's
     * official data. Null when nothing does.
     */
    private function keptInRoundBy(string $participantId, string $roundId): null|string
    {
        $entry = $this->entries[$participantId][$roundId] ?? null;

        return ParticipantRules::keptInRoundBy(
            $this->site,
            $this->playersOf($participantId),
            $roundId,
            $entry !== null && $entry['present'] && $entry['ownData'],
        );
    }

    /**
     * What keeps the person in the event, and the first round (in round order) it is in: their player's own time
     * (checked first - nobody but the player takes it away), else official data of their own entry or of their
     * pair/team. Null when nothing does.
     *
     * @return null|array{by: string, roundId: string}
     */
    private function keptInEventBy(string $participantId): null|array
    {
        $playerIds = $this->playersOf($participantId);
        $official = null;

        foreach (array_keys($this->rounds) as $roundId) {
            foreach ($playerIds as $playerId) {
                if ($this->site->hasResult($playerId, $roundId)) {
                    return ['by' => ParticipantRules::KEPT_BY_OWN_TIME, 'roundId' => $roundId];
                }
            }

            $entry = $this->entries[$participantId][$roundId] ?? null;

            if ($official === null && $entry !== null && $entry['present'] && ParticipantRules::holdsOfficialData($this->site, $entry['ownData'], $entry['team'])) {
                $official = ['by' => ParticipantRules::KEPT_BY_OFFICIAL_DATA, 'roundId' => $roundId];
            }
        }

        return $official;
    }

    /**
     * The players whose own times keep the person: linked now, and linked when the change set started.
     *
     * @return list<string>
     */
    private function playersOf(string $participantId): array
    {
        return array_values(array_unique(array_filter([
            $this->people[$participantId]['playerId'],
            $this->start['people'][$participantId]['playerId'] ?? null,
        ], is_string(...))));
    }

    /**
     * The refusal when another participant of the event (removed ones too) has the external id already - one rule with
     * the import (ParticipantRules::externalIdTakenBy()).
     */
    private function externalIdTaken(string $externalId, string $participantId, SheetChange $change, int $index): null|SheetChangeOutcome
    {
        $other = ParticipantRules::externalIdTakenBy($externalId, $participantId, $this->people);

        if ($other === null) {
            return null;
        }

        return $this->refused($change, $index, 'external_id_taken', [
            'id' => $externalId,
            'other' => $this->people[$other]['name'],
        ]);
    }

    /**
     * The message variant of a guard refusal: own time has its own text, official data the code's.
     */
    private static function cause(string $keptBy): null|string
    {
        return $keptBy === ParticipantRules::KEPT_BY_OWN_TIME ? self::CAUSE_OWN_TIME : null;
    }

    /**
     * A pair/team as the organiser knows it: its name, else its members - null for an unnamed empty one (the
     * controller says "(no name)").
     */
    private function teamLabel(string $teamId): null|string
    {
        $name = $this->teams[$teamId]['name'] ?? null;

        if ($name !== null) {
            return $name;
        }

        $names = [];
        foreach (array_keys($this->members[$teamId] ?? []) as $participantId) {
            if ($this->people[$participantId]['deleted'] === false) {
                $names[] = $this->people[$participantId]['name'];
            }
        }

        sort($names);

        return $names !== [] ? implode(', ', $names) : null;
    }

    private function warnAboutSharedName(string $teamId): void
    {
        $team = $this->teams[$teamId];

        if ($team['name'] === null) {
            return;
        }

        $key = mb_strtolower($team['name']);

        foreach ($this->teams as $otherId => $other) {
            if ($otherId !== $teamId && $other['deleted'] === false && $other['roundId'] === $team['roundId'] && $other['name'] !== null && mb_strtolower($other['name']) === $key) {
                $this->warn(new SheetWarning(SheetWarning::TEAM_NAME_SHARED, teamId: $teamId, roundId: $team['roundId'], parameters: [
                    'team' => $team['name'],
                    'round' => $this->rounds[$team['roundId']]['name'],
                ]));

                return;
            }
        }
    }

    /**
     * Pairs/teams of the group with people, but not as many as their round expects - informational, sizes never block.
     */
    private function warnAboutTeamSizes(): void
    {
        $expected = [];

        foreach (array_keys($this->touchedTeams) as $teamId) {
            $team = $this->teams[$teamId];
            $size = $this->activeMembers($teamId);

            if ($team['deleted'] || $size === 0) {
                continue;
            }

            $roundId = $team['roundId'];
            $expected[$roundId] ??= $this->expectedTeamSize($roundId);

            if ($expected[$roundId] !== null && $size !== $expected[$roundId]) {
                $this->warn(new SheetWarning(SheetWarning::TEAM_SIZE_OFF, teamId: $teamId, roundId: $roundId, parameters: [
                    'team' => $this->teamLabel($teamId),
                    'round' => $this->rounds[$roundId]['name'],
                    'count' => $size,
                    'expected' => $expected[$roundId],
                ]));
            }
        }
    }

    private function expectedTeamSize(string $roundId): null|int
    {
        $round = $this->rounds[$roundId];

        if ($round['category'] !== RoundCategory::Team || $round['teamSize'] !== null) {
            return ParticipantRules::expectedTeamSize($round['category'], $round['teamSize'], []);
        }

        $sizes = [];
        foreach ($this->teams as $teamId => $team) {
            if ($team['roundId'] === $roundId && $team['deleted'] === false) {
                $sizes[] = $this->activeMembers($teamId);
            }
        }

        return ParticipantRules::expectedTeamSize($round['category'], null, $sizes);
    }

    private function warn(SheetWarning $warning): void
    {
        $this->warnings[implode('|', [$warning->code, $warning->participantId, $warning->teamId, $warning->roundId])] ??= $warning;
    }

    /**
     * The value the server holds for the cell a change addresses - null for changes without one.
     */
    private function current(SheetChange $change): null|string|int
    {
        return match ($change->op) {
            SheetChangeOp::Field => match ($change->field) {
                SheetChangeField::Name => $this->people[(string) $change->participantId]['name'] ?? null,
                SheetChangeField::Country => $this->people[(string) $change->participantId]['country'] ?? null,
                SheetChangeField::ExternalId => $this->people[(string) $change->participantId]['externalId'] ?? null,
                SheetChangeField::Note => $this->people[(string) $change->participantId]['note'] ?? null,
                null => null,
            },
            SheetChangeOp::Player => $this->people[(string) $change->participantId]['playerId'] ?? null,
            SheetChangeOp::Place => isset($this->people[(string) $change->participantId], $this->rounds[(string) $change->roundId])
                ? $this->placeOf((string) $change->participantId, (string) $change->roundId)
                : null,
            SheetChangeOp::RenameTeam => isset($this->teams[(string) $change->teamId]) && $this->teams[(string) $change->teamId]['deleted'] === false
                ? $this->teams[(string) $change->teamId]['name']
                : null,
            SheetChangeOp::TeamSize => $this->rounds[(string) $change->roundId]['teamSize'] ?? null,
            default => null,
        };
    }

    private function outcome(SheetChange $change, int $index, SheetChangeStatus $status, null|string $reason = null): SheetChangeOutcome
    {
        return new SheetChangeOutcome($index, $status, $reason, $this->current($change));
    }

    /**
     * @param array<string, null|string|int> $parameters
     * @param null|string $cause SheetChangeOutcome::$cause
     */
    private function refused(SheetChange $change, int $index, string $reason, array $parameters = [], null|string $cause = null): SheetChangeOutcome
    {
        return new SheetChangeOutcome($index, SheetChangeStatus::Refused, $reason, $this->current($change), $parameters, $cause);
    }

    /**
     * @return State
     */
    private function state(): array
    {
        return [
            'people' => $this->people,
            'entries' => $this->entries,
            'teams' => $this->teams,
            'members' => $this->members,
            'rounds' => $this->rounds,
            'nameKeys' => $this->nameKeys,
        ];
    }

    /**
     * @param State $state
     */
    private function restore(array $state): void
    {
        $this->people = $state['people'];
        $this->entries = $state['entries'];
        $this->teams = $state['teams'];
        $this->members = $state['members'];
        $this->rounds = $state['rounds'];
        $this->nameKeys = $state['nameKeys'];
    }

    /**
     * The net difference between the event before the change set and after its applied groups.
     */
    private function operations(): ParticipantImportOperations
    {
        $start = $this->start;
        $participants = [];
        $added = 0;
        $updated = 0;
        $softDeleted = 0;
        $restored = 0;

        foreach ($this->people as $id => $person) {
            $before = $start['people'][$id] ?? null;

            if ($before === null) {
                $operation = [
                    'key' => 'new:' . $id,
                    'name' => $person['name'],
                    'country' => $person['country'],
                    'externalId' => $person['externalId'],
                    'connectPlayerId' => $person['playerId'],
                    'markAsImported' => false,
                    'restore' => false,
                    'softDelete' => $person['deleted'],
                    'changed' => true,
                    'id' => $id,
                    'source' => ParticipantSource::Manual->value,
                ];

                if ($person['note'] !== null) {
                    $operation['organizerNote'] = $person['note'];
                }

                $participants[] = $operation;
                $added++;

                continue;
            }

            if ($before === $person) {
                continue;
            }

            $operation = [
                'key' => $id,
                'name' => $person['name'],
                'country' => $person['country'],
                'externalId' => $person['externalId'],
                'connectPlayerId' => $person['playerId'] !== null && $person['playerId'] !== $before['playerId'] ? $person['playerId'] : null,
                'markAsImported' => $person['markAsImported'],
                'restore' => $before['deleted'] && !$person['deleted'],
                'softDelete' => !$before['deleted'] && $person['deleted'],
                'changed' => true,
                'disconnect' => $person['playerId'] === null && $before['playerId'] !== null,
                'leaveWaitlist' => $before['deleted'] && !$person['deleted'] && $this->registrationManaged === false,
            ];

            if ($person['note'] !== $before['note']) {
                $operation['organizerNote'] = $person['note'];
            }

            $participants[] = $operation;

            if ($operation['softDelete']) {
                $softDeleted++;
            } else {
                $updated++;
                $restored += $operation['restore'] ? 1 : 0;
            }
        }

        $participantKey = static fn (string $id): string => isset($start['people'][$id]) ? $id : 'new:' . $id;
        $teamKey = static function (null|string $teamId) use ($start): null|string {
            if ($teamId === null) {
                return null;
            }

            return isset($start['teams'][$teamId]) ? 't:' . $teamId : 'n:' . $teamId;
        };

        $newEntries = [];
        $entryTeams = [];
        $deletedEntries = [];

        foreach ($this->entries as $participantId => $rounds) {
            foreach ($rounds as $roundId => $entry) {
                if ($entry['id'] === null) {
                    if ($entry['present']) {
                        $newEntries[] = ['participantKey' => $participantKey($participantId), 'roundId' => $roundId, 'team' => $teamKey($entry['team'])];
                    }

                    continue;
                }

                if ($entry['present'] === false) {
                    $deletedEntries[] = $entry['id'];
                } elseif ($entry['team'] !== $start['entries'][$participantId][$roundId]['team']) {
                    $entryTeams[] = ['entryId' => $entry['id'], 'team' => $teamKey($entry['team'])];
                }
            }
        }

        $newTeams = [];
        $deletedTeams = [];
        $renamedTeams = [];

        foreach ($this->teams as $teamId => $team) {
            if ($team['new']) {
                // Left without a name and without anybody by the change set: nothing to keep - never created
                $leftEmpty = $team['name'] === null && ($this->members[$teamId] ?? []) === [];

                if ($team['deleted'] === false && $leftEmpty === false) {
                    $newTeams[] = ['key' => 'n:' . $teamId, 'roundId' => $team['roundId'], 'name' => $team['name'], 'id' => $teamId];
                }

                continue;
            }

            if ($team['deleted']) {
                $deletedTeams[] = $teamId;
            } elseif ($team['name'] !== $start['teams'][$teamId]['name']) {
                $renamedTeams[] = ['teamId' => $teamId, 'name' => $team['name']];
            }
        }

        return new ParticipantImportOperations(
            participants: $participants,
            newTeams: $newTeams,
            newEntries: $newEntries,
            entryTeams: $entryTeams,
            deletedEntries: $deletedEntries,
            deletedTeams: $deletedTeams,
            added: $added,
            updated: $updated,
            softDeleted: $softDeleted,
            restored: $restored,
            removed: $softDeleted,
            renamedTeams: $renamedTeams,
        );
    }

    /**
     * @return array<string, null|int> round id => its new expected team size
     */
    private function teamSizes(): array
    {
        $sizes = [];

        foreach ($this->rounds as $roundId => $round) {
            if ($round['teamSize'] !== $this->start['rounds'][$roundId]['teamSize']) {
                $sizes[$roundId] = $round['teamSize'];
            }
        }

        return $sizes;
    }

    /**
     * A participant's name as organisers type it: single spaces, nothing around it - null when nothing is left.
     */
    private static function cleanName(null|string $name): null|string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name ?? ''));

        return $name === '' ? null : $name;
    }

    /**
     * External ids and notes: trimmed, empty means none.
     */
    private static function cleanText(null|string $value): null|string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * @return null|string|false the CountryCode case name, null for none, false for a country we do not know
     */
    private static function country(null|string $value): null|string|false
    {
        $value = trim($value ?? '');

        if ($value === '') {
            return null;
        }

        return CountryCode::fromCode($value)->name ?? false;
    }
}
