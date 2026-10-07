<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport\Plan;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Results\ParticipantImportRemovals;
use SpeedPuzzling\Web\Results\ParticipantImportRow;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRound;
use SpeedPuzzling\Web\Value\ParticipantImportRowAction;
use SpeedPuzzling\Web\Value\ParticipantImportRowData;
use SpeedPuzzling\Web\Value\ParticipantImportRows;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * One planning run of ParticipantImportPlanner: the file's rows applied to a virtual copy of the event, nothing written.
 *
 * The rules of the import (docs/features/competitions-management/participants.md §Excel Import and
 * participant-import-preview.md) - every one of them as the importer has followed them so far:
 * - a row is matched to an existing participant by participant_id, msp_player_id, external_id, then name
 *   (+ country); a name shared by several participants of the event is never guessed - the row is reported
 * - a row matching nobody by name but exactly one active participant by the name key (D17) is that participant
 * - rows of the same person add up: every round of every row is assigned, the person counts once; later rows see
 *   what earlier rows changed
 * - "Update only" only adds round entries and fills missing teams; full sync also takes people out of rounds the
 *   file does not list (when a rounds column is mapped), moves people between teams in rounds that have their own
 *   team column, removes participants the file does not have and deletes the pairs/teams it empties - never what
 *   has results (D11)
 * - an export imported back unchanged changes nothing
 */
final class PlanBuilder
{
    public const string MESSAGE_PREFIX = 'competition.participants.import.';

    /** @var array<string, ParticipantImportRound> */
    private array $roundsById = [];

    /** @var array<string, ParticipantImportRound> roundKey() => round, the first of rounds the import cannot tell apart */
    private array $roundsByKey = [];

    /** @var list<non-empty-list<string>> */
    private array $roundCollisions = [];

    /** @var array<string, array{id: string, roundId: string, name: null|string}> */
    private array $teams;

    /** @var array<string, array<string, list<string>>> round id => team name key => existing team ids */
    private array $teamsByName = [];

    /** @var array<string, array{roundId: string, name: string}> new team key => team */
    private array $newTeams = [];

    /** @var array<string, PlanPerson> */
    private array $people = [];

    /**
     * Today's matching pool: every participant a row may match, active first; people the file creates are added.
     *
     * @var array<string, array{id: string, name: string, country: null|string, external_id: null|string, player_id: null|string, deleted: bool}>
     */
    private array $pool = [];

    /** @var array<string, string> participant key => name key (D17), for the pool */
    private array $nameKeys = [];

    /** @var array<int, PlanRow> */
    private array $rows = [];

    /** @var array<string, list<PlanEntry>> person key => round entries */
    private array $entries = [];

    /**
     * @var array<string, array<string, array{team: null|string, canFill: bool, row: int, own: bool}>> person key => [round id => assignment]
     */
    private array $assignments = [];

    /** @var array<string, true> rounds with their own team column - teams are synced there (D14) */
    private array $ownTeamColumnRounds = [];

    /** @var array<string, list<int>> round name as written => row numbers */
    private array $unknownRounds = [];

    /** @var array<string, true> participants a skipped ambiguous row may have meant - never removed */
    private array $ambiguousCandidates = [];

    /** @var list<int> */
    private array $missingNameRows = [];

    /** @var list<int> */
    private array $ambiguousRows = [];

    private int $foreignParticipantIds = 0;

    /** @var array<string, true> person key => the import changes their round entries or teams */
    private array $entriesChanged = [];

    /** @var list<TranslatableMessage> */
    private array $warnings = [];

    /** @var list<array{participantId: string, name: string, country: null|string, playerName: null|string}> */
    private array $removedParticipants = [];

    /** @var list<array{participantId: string, name: string, country: null|string, playerName: null|string}> */
    private array $removedSelfJoined = [];

    /** @var list<array{participantId: string, name: string, rounds: list<string>}> */
    private array $keptWithResults = [];

    /** @var list<array{entryId: string, participantName: string, roundName: string, teamName: null|string}> */
    private array $removedEntries = [];

    /** @var list<array{entryId: string, participantName: string, roundName: string}> */
    private array $keptEntries = [];

    /** @var list<array{participantName: string, roundName: string, from: null|string, to: null|string}> */
    private array $teamChanges = [];

    /** @var list<array{teamId: string, roundName: string, teamName: null|string, activeMembers: int}> */
    private array $deletedTeams = [];

    public function __construct(
        private readonly SiteSnapshot $site,
        private readonly ParticipantImportRows $file,
        private readonly ParticipantImportMode $mode,
    ) {
        foreach ($site->rounds as $round) {
            $this->roundsById[$round->id] = $round;
        }

        /** @var array<string, non-empty-list<string>> $namesByKey */
        $namesByKey = [];
        foreach ($site->rounds as $round) {
            $key = self::roundKey($round->name);
            $this->roundsByKey[$key] ??= $round;
            $namesByKey[$key][] = $round->name;
        }
        $this->roundCollisions = array_values(array_filter($namesByKey, static fn (array $names): bool => count($names) > 1));

        $this->teams = $site->teams;
        foreach ($site->teams as $team) {
            if ($team['name'] !== null) {
                $this->teamsByName[$team['roundId']][self::teamKey($team['name'])][] = $team['id'];
            }
        }

        foreach ($site->participants as $participant) {
            $person = new PlanPerson(
                key: $participant['id'],
                id: $participant['id'],
                name: $participant['name'],
                country: $participant['country'],
                externalId: $participant['externalId'],
                playerId: $participant['playerId'],
                deleted: $participant['deletedAt'] !== null,
                deletedAt: $participant['deletedAt'],
                selfJoined: $participant['selfJoined'],
                playerName: $participant['playerName'],
            );
            $person->before = $person->state();
            $this->people[$person->key] = $person;

            // A player's own "I left" record is not the organiser's data: restoring it would sign them up again
            if (!($participant['selfJoined'] && $participant['deletedAt'] !== null)) {
                $this->pool[$person->key] = self::poolRow($person);
                $this->nameKeys[$person->key] = ParticipantNameKey::of($person->name);
            }
        }

        foreach ($site->entries as $entry) {
            $team = $entry['teamId'] !== null ? 't:' . $entry['teamId'] : null;
            $this->entries[$entry['participantId']][] = new PlanEntry($entry['id'], $entry['participantId'], $entry['roundId'], $team, $team);
        }

        foreach ($file->rows as $row) {
            foreach (array_keys($row->teamsByRound) as $roundId) {
                if (isset($this->roundsById[$roundId]) && $this->roundsById[$roundId]->hasTeams()) {
                    $this->ownTeamColumnRounds[$roundId] = true;
                }
            }
        }
    }

    public function build(): BuiltPlan
    {
        foreach ($this->file->rows as $row) {
            $this->planRow($row);
        }

        $this->planRoundsAndTeams();

        if ($this->mode === ParticipantImportMode::Sync) {
            $this->planRemovals();
        }

        $this->warnAboutTeamSizes();
        $this->warnAboutNames();

        return $this->result();
    }

    private function planRow(ParticipantImportRowData $data): void
    {
        $rowNum = $data->rowNumber;
        $row = new PlanRow($rowNum, $data->name);
        $this->rows[$rowNum] = $row;

        $name = $data->name;
        if ($name === '') {
            $row->messages[] = self::message('missing_name', ['%row%' => $rowNum]);
            $this->missingNameRows[] = $rowNum;

            return;
        }

        $country = $data->country ?? '';
        $externalId = $data->externalId ?? '';
        $playerId = $data->playerId ?? '';
        $status = strtolower($data->status ?? '');
        $participantIdCell = $data->participantId ?? '';

        $countryCode = null;
        if ($country !== '') {
            $countryCode = CountryCode::fromCode($country);
            if ($countryCode === null) {
                $row->messages[] = self::message('invalid_country', ['%row%' => $rowNum, '%country%' => $country]);
            }
        }

        $validPlayerId = null;
        if ($playerId !== '') {
            if (!Uuid::isValid($playerId)) {
                $row->messages[] = self::message('invalid_player_id', ['%row%' => $rowNum, '%id%' => $playerId]);
            } elseif (!isset($this->site->existingPlayers[strtolower($playerId)])) {
                $row->messages[] = self::message('unknown_player_id', ['%row%' => $rowNum, '%id%' => $playerId]);
            } else {
                $validPlayerId = strtolower($playerId);
            }
        }

        // Priority 0: the participant's own id (the export writes it)
        $matchedKey = null;
        $byNameKey = false;
        if ($participantIdCell !== '') {
            $normalisedId = strtolower($participantIdCell);

            if (Uuid::isValid($normalisedId) && isset($this->pool[$normalisedId])) {
                $matchedKey = $normalisedId;
            } else {
                $row->messages[] = self::message('foreign_participant_id', ['%row%' => $rowNum, '%id%' => $participantIdCell]);
                $this->foreignParticipantIds++;
            }
        }

        if ($matchedKey === null) {
            $match = $this->findMatch($validPlayerId, $externalId !== '' ? $externalId : null, $name, $countryCode?->name);

            if (is_array($match)) {
                $row->messages[] = self::message('ambiguous_name', ['%row%' => $rowNum, '%name%' => $name, '%count%' => count($match)]);
                $this->ambiguousRows[] = $rowNum;
                foreach ($match as $candidate) {
                    $this->ambiguousCandidates[$candidate] = true;
                }

                return;
            }

            $matchedKey = $match;

            if ($matchedKey === null) {
                $matchedKey = $this->findMatchByNameKey($validPlayerId, $externalId !== '' ? $externalId : null, $name, $countryCode?->name);
                $byNameKey = $matchedKey !== null;
            }
        }

        if ($matchedKey !== null) {
            $person = $this->people[$matchedKey];

            // A spelling matched by the name key renames the person once - a further row of theirs written another
            // way does not flip the name back and forth (it is reported, D17)
            if (!$byNameKey || !$person->inFile) {
                $person->name = $name;
            }
            if ($person->selfJoined) {
                $person->markAsImported = true;
            }
            if ($countryCode !== null) {
                $person->country = $countryCode->name;
            }
            if ($externalId !== '') {
                $person->externalId = $externalId;
            }
            if ($validPlayerId !== null) {
                $person->playerId = $validPlayerId;
            }
            $person->deleted = false;
        } else {
            $person = new PlanPerson(
                key: 'new:' . $rowNum,
                id: null,
                name: $name,
                country: $countryCode?->name,
                externalId: $externalId !== '' ? $externalId : null,
                playerId: $validPlayerId,
                deleted: false,
            );
            $this->people[$person->key] = $person;
        }

        $person->inFile = true;
        $person->rows[] = $rowNum;
        $row->personKey = $person->key;

        if ($status === 'deleted') {
            $person->deleted = true;
            $person->deletedByFile = true;
        }

        // Later rows match what this row made of the participant (a rename, a new external id, ...)
        $this->pool[$person->key] = self::poolRow($person);
        $this->nameKeys[$person->key] = ParticipantNameKey::of($person->name);

        // A participant removed by the file gets no rounds
        if ($status === 'deleted') {
            return;
        }

        $teamName = $this->cleanTeamName($data->team ?? '', $row);

        /** @var array<string, null|string> $rowTeams round id => the row's own team cell of that round */
        $rowTeams = [];
        foreach ($data->teamsByRound as $roundId => $cell) {
            if (isset($this->ownTeamColumnRounds[$roundId])) {
                $rowTeams[$roundId] = $this->cleanTeamName($cell, $row);
            }
        }

        $requestedRoundNames = $data->roundNames !== null ? $this->splitRoundNames($data->roundNames) : [];
        if ($data->roundName !== null && $data->roundName !== '') {
            $requestedRoundNames[] = $data->roundName;
        }
        if ($requestedRoundNames !== []) {
            $person->listsRounds = true;
        }

        /** @var array<string, ParticipantImportRound> $rowRounds */
        $rowRounds = [];
        foreach ($requestedRoundNames as $requestedRoundName) {
            $round = $this->roundsByKey[self::roundKey($requestedRoundName)] ?? null;

            if ($round === null) {
                $this->unknownRounds[$requestedRoundName][] = $rowNum;

                continue;
            }

            $rowRounds[$round->id] = $round;
            $person->roundsListed[$round->id] = true;
        }

        foreach ($rowTeams as $roundId => $roundTeam) {
            if ($roundTeam !== null && !isset($rowRounds[$roundId])) {
                $row->messages[] = self::message('team_round_not_listed', [
                    '%row%' => $rowNum,
                    '%team%' => $roundTeam,
                    '%round%' => $this->roundsById[$roundId]->name,
                ]);
            }
        }

        // A filled own team cell of a round wins; otherwise team_name applies. A team_name covering several
        // pair/team rounds (an export made before the per-round columns) never joins a team somebody already
        // in the round has not got - it may belong to another round.
        $genericTeamRounds = array_filter(
            $rowRounds,
            static fn (ParticipantImportRound $round): bool => $round->hasTeams() && ($rowTeams[$round->id] ?? null) === null,
        );
        $genericTeamIsAmbiguous = $teamName !== null && count($genericTeamRounds) > 1;

        foreach ($rowRounds as $roundId => $round) {
            if ($round->hasTeams()) {
                $row->teamRoundIds[] = $roundId;
            }

            if (!$round->hasTeams()) {
                $assignment = ['team' => null, 'canFill' => true, 'row' => $rowNum, 'own' => false];
            } elseif (($rowTeams[$roundId] ?? null) !== null) {
                $assignment = ['team' => $rowTeams[$roundId], 'canFill' => true, 'row' => $rowNum, 'own' => true];
            } else {
                $assignment = ['team' => $teamName, 'canFill' => !$genericTeamIsAmbiguous, 'row' => $rowNum, 'own' => false];
            }

            $current = $this->assignments[$person->key][$roundId] ?? null;

            if (
                $current === null
                || ($current['team'] === null && $assignment['team'] !== null)
                // The same team, named for this one round by a later row, may fill what an ambiguous row could not
                || ($assignment['canFill'] && !$current['canFill'] && $assignment['team'] !== null && self::sameTeamName($assignment['team'], $current['team']))
            ) {
                $this->assignments[$person->key][$roundId] = $assignment;
            } elseif ($assignment['team'] !== null && !self::sameTeamName($assignment['team'], $current['team'])) {
                $row->messages[] = self::message('team_conflict_in_file', [
                    '%row%' => $rowNum,
                    '%name%' => $name,
                    '%team%' => (string) $current['team'],
                    '%round%' => $round->name,
                    '%ignored%' => $assignment['team'],
                ]);
            }
        }
    }

    /**
     * Round entries and teams. "Update only" (today's rule): a participant already in a round stays there with their
     * team, a missing team is filled in, a different team in the file is reported, never switched. Full sync, in a
     * round with its own team column (D15): the file's team wins - moved to the one team of that name (none → a new
     * one, several → not guessed), an empty cell takes them out of a named team, an unnamed team stays.
     */
    private function planRoundsAndTeams(): void
    {
        foreach ($this->assignments as $personKey => $personRounds) {
            $person = $this->people[$personKey];

            foreach ($personRounds as $roundId => $assignment) {
                $round = $this->roundsById[$roundId];
                $row = $this->rows[$assignment['row']];
                $teamName = $assignment['team'];
                $entry = $this->entryOf($personKey, $roundId);

                $syncsTeam = $this->mode === ParticipantImportMode::Sync
                    && $round->hasTeams()
                    && isset($this->ownTeamColumnRounds[$roundId])
                    && ($assignment['own'] || $teamName === null);

                if ($entry !== null && $syncsTeam) {
                    $this->syncTeam($person, $entry, $round, $teamName, $row);

                    continue;
                }

                if ($entry !== null && ($teamName === null || $entry->team !== null)) {
                    if ($teamName !== null && !self::sameTeamName($teamName, $this->teamName($entry->team))) {
                        $row->messages[] = self::message('team_kept', [
                            '%name%' => $person->name,
                            '%team%' => $this->teamName($entry->team) ?? '',
                            '%round%' => $round->name,
                            '%ignored%' => $teamName,
                        ]);
                    }

                    continue;
                }

                if ($entry !== null && !$assignment['canFill']) {
                    $row->messages[] = self::message('team_not_filled', [
                        '%row%' => $assignment['row'],
                        '%team%' => (string) $teamName,
                        '%round%' => $round->name,
                        '%name%' => $person->name,
                    ]);

                    continue;
                }

                $team = null;
                if ($teamName !== null) {
                    $team = $this->resolveTeam($round, $teamName);

                    if (is_int($team)) {
                        $row->messages[] = self::message('warning.team_name_shared', [
                            '%row%' => $assignment['row'],
                            '%count%' => $team,
                            '%team%' => $teamName,
                            '%round%' => $round->name,
                            '%name%' => $person->name,
                        ]);

                        if ($entry !== null) {
                            continue;
                        }

                        $team = null;
                    }
                }

                // Already in the round without a team (other cases continued above): fill the team in
                if ($entry !== null) {
                    $entry->team = $team;
                    $this->entriesChanged[$personKey] = true;

                    continue;
                }

                $this->entries[$personKey][] = new PlanEntry(null, $personKey, $roundId, $team, null, $assignment['row']);
                $this->entriesChanged[$personKey] = true;
            }
        }
    }

    private function syncTeam(PlanPerson $person, PlanEntry $entry, ParticipantImportRound $round, null|string $teamName, PlanRow $row): void
    {
        $currentName = $this->teamName($entry->team);

        if ($teamName === null) {
            // An unnamed team stays: a file cannot name it, and the export writes an empty cell for it
            if ($entry->team !== null && $currentName !== null) {
                $this->teamChanges[] = ['participantName' => $person->name, 'roundName' => $round->name, 'from' => $currentName, 'to' => null];
                $entry->team = null;
                $this->entriesChanged[$person->key] = true;
            }

            return;
        }

        if ($entry->team !== null && self::sameTeamName($teamName, $currentName)) {
            return;
        }

        $team = $this->resolveTeam($round, $teamName);

        if (is_int($team)) {
            $row->messages[] = self::message($entry->team === null ? 'warning.team_name_shared' : 'warning.team_name_shared_kept', [
                '%row%' => $row->rowNumber,
                '%count%' => $team,
                '%team%' => $teamName,
                '%round%' => $round->name,
                '%name%' => $person->name,
                '%current%' => $currentName ?? '',
            ]);

            return;
        }

        if ($entry->team !== null) {
            $this->teamChanges[] = ['participantName' => $person->name, 'roundName' => $round->name, 'from' => $currentName, 'to' => $this->teamName($team)];
        }

        $entry->team = $team;
        $this->entriesChanged[$person->key] = true;
    }

    /**
     * Full sync (D7, D10-D12, D14): what the site has and the file does not.
     */
    private function planRemovals(): void
    {
        // Round entries of the people in the file - only when a rounds column is mapped, never on an empty cell
        if ($this->file->roundsMapped) {
            foreach ($this->people as $person) {
                if (!$person->inFile || $person->isNew() || $person->deleted) {
                    continue;
                }

                $existing = array_values(array_filter($this->entries[$person->key] ?? [], static fn (PlanEntry $entry): bool => !$entry->isNew()));

                if ($existing === []) {
                    continue;
                }

                if (!$person->listsRounds) {
                    $firstRow = $this->rows[$person->rows[0]];
                    $firstRow->messages[] = self::message('warning.rounds_kept', [
                        '%row%' => $firstRow->rowNumber,
                        '%name%' => $person->name,
                    ]);

                    continue;
                }

                foreach ($existing as $entry) {
                    if (isset($person->roundsListed[$entry->roundId])) {
                        continue;
                    }

                    $roundName = $this->roundsById[$entry->roundId]->name;

                    if ($this->site->hasResult($person->playerId, $entry->roundId)) {
                        $this->keptEntries[] = ['entryId' => (string) $entry->id, 'participantName' => $person->name, 'roundName' => $roundName];

                        continue;
                    }

                    $entry->removed = true;
                    $this->entriesChanged[$person->key] = true;
                    $this->removedEntries[] = [
                        'entryId' => (string) $entry->id,
                        'participantName' => $person->name,
                        'roundName' => $roundName,
                        'teamName' => $this->teamName($entry->originalTeam),
                    ];
                }
            }
        }

        // Participants the file does not have
        foreach ($this->site->participants as $participant) {
            $person = $this->people[$participant['id']];

            if ($person->inFile || !$person->wasActive() || isset($this->ambiguousCandidates[$person->key])) {
                continue;
            }

            if ($this->site->hasResult($person->playerId)) {
                $this->keptWithResults[] = [
                    'participantId' => $participant['id'],
                    'name' => $person->name,
                    'rounds' => $this->roundNamesWithResults((string) $person->playerId),
                ];

                continue;
            }

            $removal = [
                'participantId' => $participant['id'],
                'name' => $person->name,
                'country' => $person->country,
                'playerName' => $person->playerName,
            ];

            if ($person->selfJoined) {
                // Made the organiser's first: a self-joined removed row is the player's own "I left" record, which
                // the import never matches and "I'm going" silently restores (D10)
                $person->markAsImported = true;
                $this->removedSelfJoined[] = $removal;
            } else {
                $this->removedParticipants[] = $removal;
            }

            $person->deleted = true;
            $person->removedBySync = true;
        }

        // Pairs/teams the import empties: at least one active member before, none after. Teams that were empty
        // already (made in advance) stay.
        $activeBefore = [];
        foreach ($this->site->entries as $entry) {
            if ($entry['teamId'] !== null && $this->people[$entry['participantId']]->wasActive()) {
                $activeBefore[$entry['teamId']] = ($activeBefore[$entry['teamId']] ?? 0) + 1;
            }
        }

        $activeAfter = $this->activeMembers();

        foreach ($activeBefore as $teamId => $members) {
            if (($activeAfter['t:' . $teamId] ?? 0) > 0) {
                continue;
            }

            $team = $this->teams[$teamId];
            $this->deletedTeams[] = [
                'teamId' => $teamId,
                'roundName' => $this->roundsById[$team['roundId']]->name,
                'teamName' => $team['name'],
                'activeMembers' => $members,
            ];
        }
    }

    /**
     * @return array<string, int> team key => active members after the import
     */
    private function activeMembers(): array
    {
        $members = [];

        foreach ($this->entries as $personKey => $entries) {
            if ($this->people[$personKey]->deleted) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!$entry->removed && $entry->team !== null) {
                    $members[$entry->team] = ($members[$entry->team] ?? 0) + 1;
                }
            }
        }

        return $members;
    }

    /**
     * D16 (c): more people under one name than the round's teams usually have, and pairs/teams of one person -
     * only teams somebody of the file is in.
     */
    private function warnAboutTeamSizes(): void
    {
        $members = $this->activeMembers();

        /** @var array<string, array<string, true>> $fileTeams round id => team keys somebody of the file is in */
        $fileTeams = [];
        foreach ($this->entries as $personKey => $entries) {
            $person = $this->people[$personKey];

            if (!$person->inFile || $person->deleted) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!$entry->removed && $entry->team !== null && isset($person->roundsListed[$entry->roundId])) {
                    $fileTeams[$entry->roundId][$entry->team] = true;
                }
            }
        }

        foreach ($this->site->rounds as $round) {
            if (!$round->hasTeams() || !isset($fileTeams[$round->id])) {
                continue;
            }

            $teamKeys = array_keys($fileTeams[$round->id]);
            $usual = $round->category === RoundCategory::Duo ? 2 : $this->usualTeamSize($round, $teamKeys, $members);

            $singles = [];
            foreach ($teamKeys as $teamKey) {
                $size = $members[$teamKey] ?? 0;
                $name = $this->teamName($teamKey);

                if ($name === null) {
                    continue;
                }

                if ($size > $usual) {
                    $this->warnings[] = self::message($round->category === RoundCategory::Duo ? 'warning.pair_oversized' : 'warning.team_oversized', [
                        '%count%' => $size,
                        '%team%' => $name,
                        '%round%' => $round->name,
                        '%usual%' => $usual,
                    ]);
                } elseif ($size === 1) {
                    $singles[] = $name;
                }
            }

            if ($singles !== []) {
                sort($singles);
                $this->warnings[] = self::message('warning.team_single', [
                    '%count%' => count($singles),
                    '%teams%' => self::quotedList($singles),
                    '%round%' => $round->name,
                ]);
            }
        }
    }

    /**
     * The most common team size of the round - of the teams the file fills, else of the site's teams; at least 2.
     *
     * @param list<string> $fileTeamKeys
     * @param array<string, int> $members
     */
    private function usualTeamSize(ParticipantImportRound $round, array $fileTeamKeys, array $members): int
    {
        $sizes = [];
        foreach ($fileTeamKeys as $teamKey) {
            if (($members[$teamKey] ?? 0) > 0) {
                $sizes[] = $members[$teamKey];
            }
        }

        if ($sizes === []) {
            foreach ($this->teams as $team) {
                if ($team['roundId'] === $round->id && ($members['t:' . $team['id']] ?? 0) > 0) {
                    $sizes[] = $members['t:' . $team['id']];
                }
            }
        }

        if ($sizes === []) {
            return 2;
        }

        $counts = array_count_values($sizes);
        // The most common size; on a tie the smaller one
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
     * D17 (a) one name written in several ways in the file, (c) a new person looking like somebody on the site who is
     * not in the file. Never merged by themselves.
     */
    private function warnAboutNames(): void
    {
        /** @var array<string, array<string, list<int>>> $spellings name key => [spelling => rows] */
        $spellings = [];
        foreach ($this->rows as $row) {
            if ($row->name !== '') {
                $spellings[ParticipantNameKey::of($row->name)][$row->name][] = $row->rowNumber;
            }
        }

        foreach ($spellings as $variants) {
            if (count($variants) < 2) {
                continue;
            }

            $rows = [];
            foreach ($variants as $variantRows) {
                $rows = [...$rows, ...$variantRows];
            }
            sort($rows);

            $this->warnings[] = self::message('warning.name_spellings', [
                '%names%' => self::quotedList(array_map(strval(...), array_keys($variants))),
                '%rows%' => self::listRowNumbers($rows),
            ]);
        }

        $notInFile = [];
        foreach ($this->people as $person) {
            if (!$person->inFile && $person->wasActive() && !isset($this->ambiguousCandidates[$person->key])) {
                $notInFile[] = $person;
            }
        }

        foreach ($this->people as $person) {
            if (!$person->isNew() || $person->deleted) {
                continue;
            }

            $key = ParticipantNameKey::of($person->name);
            if (mb_strlen($key) < 6) {
                continue;
            }

            foreach ($notInFile as $other) {
                $otherKey = ParticipantNameKey::of($other->name);

                if (mb_strlen($otherKey) >= 6 && levenshtein($key, $otherKey) <= 2) {
                    $this->warnings[] = self::message('warning.similar_name', [
                        '%new%' => $person->name,
                        '%row%' => $person->rows[0],
                        '%existing%' => $other->name,
                    ]);
                }
            }
        }
    }

    private function result(): BuiltPlan
    {
        $rows = [];
        $participantOps = [];
        $added = 0;
        $updated = 0;
        $unchanged = 0;
        $softDeleted = 0;
        $restored = 0;

        /** @var array<string, ParticipantImportRowAction> $actions person key => action of their first row */
        $actions = [];
        foreach ($this->people as $person) {
            if (!$person->inFile) {
                continue;
            }

            $before = $person->before;
            $changed = $before === null || $before !== $person->state() || isset($this->entriesChanged[$person->key]);

            if ($person->deleted && ($before === null || $before['deleted'] === false)) {
                $softDeleted++;
                $actions[$person->key] = ParticipantImportRowAction::Remove;
            } elseif ($before === null) {
                $added++;
                $actions[$person->key] = ParticipantImportRowAction::New;
            } elseif ($changed) {
                $updated++;
                if ($person->isRestored()) {
                    $restored++;
                    $actions[$person->key] = ParticipantImportRowAction::Restore;
                } else {
                    $actions[$person->key] = ParticipantImportRowAction::Update;
                }
            } else {
                $unchanged++;
                $actions[$person->key] = ParticipantImportRowAction::Unchanged;
            }
        }

        foreach ($this->people as $person) {
            if (!$person->inFile && !$person->removedBySync) {
                continue;
            }

            $before = $person->before;
            $fieldsChanged = $before === null || $before !== $person->state();

            if (!$fieldsChanged && !$person->markAsImported) {
                continue;
            }

            $participantOps[] = [
                'key' => $person->key,
                'name' => $person->name,
                'country' => $person->country,
                'externalId' => $person->externalId,
                'connectPlayerId' => $person->playerId !== null && $person->playerId !== ($before['playerId'] ?? null) ? $person->playerId : null,
                'markAsImported' => $person->markAsImported,
                'restore' => $person->isRestored(),
                'softDelete' => $person->deleted && ($before === null || $before['deleted'] === false),
                'changed' => $fieldsChanged,
            ];
        }

        $usedNewTeams = [];
        $newEntries = [];
        $entryTeams = [];
        $deletedEntries = [];
        /** @var array<int, list<string>> $roundsAddedByRow */
        $roundsAddedByRow = [];
        foreach ($this->entries as $personKey => $entries) {
            foreach ($entries as $entry) {
                if ($entry->team !== null && str_starts_with($entry->team, 'n:') && !$entry->removed) {
                    $usedNewTeams[$entry->team] = true;
                }

                if ($entry->isNew()) {
                    $newEntries[] = ['participantKey' => $personKey, 'roundId' => $entry->roundId, 'team' => $entry->team];
                    if ($entry->row !== null) {
                        $roundsAddedByRow[$entry->row][] = $this->roundsById[$entry->roundId]->name;
                    }
                } elseif ($entry->removed) {
                    $deletedEntries[] = (string) $entry->id;
                } elseif ($entry->team !== $entry->originalTeam) {
                    $entryTeams[] = ['entryId' => (string) $entry->id, 'team' => $entry->team];
                }
            }
        }

        $newTeams = [];
        foreach ($this->newTeams as $key => $team) {
            if (isset($usedNewTeams[$key])) {
                $newTeams[] = ['key' => $key, 'roundId' => $team['roundId'], 'name' => $team['name']];
            }
        }

        $seenPeople = [];
        foreach ($this->rows as $rowNumber => $row) {
            $personKey = $row->personKey;

            if ($personKey === null) {
                $rows[] = new ParticipantImportRow(
                    rowNumber: $rowNumber,
                    name: $row->name,
                    action: ParticipantImportRowAction::Skipped,
                    messages: $row->messages,
                );

                continue;
            }

            $person = $this->people[$personKey];
            $first = !isset($seenPeople[$personKey]);
            $seenPeople[$personKey] = true;

            $teams = [];
            foreach ($row->teamRoundIds as $roundId) {
                $entry = $this->entryOf($personKey, $roundId);
                $teams[$this->roundsById[$roundId]->name] = $entry !== null && !$entry->removed ? $this->teamName($entry->team) : null;
            }

            $rows[] = new ParticipantImportRow(
                rowNumber: $rowNumber,
                name: $row->name,
                action: $first ? $actions[$personKey] : ParticipantImportRowAction::SamePerson,
                participantId: $person->id,
                changes: $first ? $this->changesOf($person) : [],
                roundsAdded: $roundsAddedByRow[$rowNumber] ?? [],
                roundsRemoved: $first ? $this->roundsRemovedOf($person) : [],
                teams: $teams,
                messages: $row->messages,
                removedAt: $first && $person->isRestored() ? $person->deletedAt : null,
                roundsRestored: $first && $person->isRestored() ? $this->roundsRestoredOf($person) : [],
            );
        }

        $warnings = [];
        foreach ($this->roundCollisions as $collision) {
            $warnings[] = self::message('round_case_collision', [
                '%rounds%' => self::quotedList($collision),
                '%used%' => $collision[0],
            ]);
        }
        foreach ($this->unknownRounds as $unknownRoundName => $rowNumbers) {
            $warnings[] = self::message('unknown_round', [
                '%round%' => (string) $unknownRoundName,
                '%rows%' => self::listRowNumbers($rowNumbers),
                '%count%' => count($rowNumbers),
                '%rounds%' => $this->site->rounds === []
                    ? self::message('no_rounds_yet')
                    : self::quotedList(array_map(static fn (ParticipantImportRound $round): string => $round->name, array_values($this->roundsByKey))),
            ]);
        }

        return new BuiltPlan(
            rows: $rows,
            warnings: [...$warnings, ...$this->warnings],
            removals: new ParticipantImportRemovals(
                participants: $this->removedParticipants,
                selfJoined: $this->removedSelfJoined,
                participantsKeptWithResults: $this->keptWithResults,
                roundEntries: $this->removedEntries,
                roundEntriesKeptWithResults: $this->keptEntries,
                teamChanges: $this->teamChanges,
                teams: $this->deletedTeams,
            ),
            operations: new ParticipantImportOperations(
                participants: $participantOps,
                newTeams: $newTeams,
                newEntries: $newEntries,
                entryTeams: $entryTeams,
                deletedEntries: $deletedEntries,
                deletedTeams: array_map(static fn (array $team): string => $team['teamId'], $this->deletedTeams),
                added: $added,
                updated: $updated,
                unchanged: $unchanged,
                softDeleted: $softDeleted,
                restored: $restored,
                removed: count($this->removedParticipants) + count($this->removedSelfJoined),
            ),
            syncBlockers: $this->syncBlockers(),
            activeParticipantsBefore: count(array_filter($this->site->participants, static fn (array $participant): bool => $participant['deletedAt'] === null)),
            resultsGuard: $this->resultsGuard(),
        );
    }

    /**
     * D7b: full sync is refused when the plan cannot vouch for every row.
     *
     * @return list<TranslatableMessage>
     */
    private function syncBlockers(): array
    {
        $blockers = [];

        if ($this->missingNameRows !== []) {
            $blockers[] = self::message('plan.blocker.missing_name', [
                '%count%' => count($this->missingNameRows),
                '%rows%' => self::listRowNumbers($this->missingNameRows),
            ]);
        }

        if ($this->ambiguousRows !== []) {
            $blockers[] = self::message('plan.blocker.ambiguous_name', [
                '%count%' => count($this->ambiguousRows),
                '%rows%' => self::listRowNumbers($this->ambiguousRows),
            ]);
        }

        if ($this->unknownRounds !== []) {
            $blockers[] = self::message('plan.blocker.unknown_round', [
                '%count%' => count($this->unknownRounds),
                '%rounds%' => self::quotedList(array_map(strval(...), array_keys($this->unknownRounds))),
            ]);
        }

        foreach ($this->roundCollisions as $collision) {
            $blockers[] = self::message('plan.blocker.round_case_collision', ['%rounds%' => self::quotedList($collision)]);
        }

        if ($this->foreignParticipantIds > 3) {
            $blockers[] = self::message('plan.blocker.foreign_participant_ids', ['%count%' => $this->foreignParticipantIds]);
        }

        $activeBefore = 0;
        $matchedActive = 0;
        foreach ($this->people as $person) {
            if ($person->wasActive()) {
                $activeBefore++;

                if ($person->inFile) {
                    $matchedActive++;
                }
            }
        }

        if ($activeBefore > 0 && $matchedActive === 0) {
            $blockers[] = self::message('plan.blocker.no_match', ['%count%' => $activeBefore]);
        }

        return $blockers;
    }

    /**
     * What full sync keeps because of results - part of the fingerprint, so a result arriving after the preview for
     * somebody the preview removed makes the preview stale (D8), while results of everybody else do not.
     */
    private function resultsGuard(): string
    {
        $kept = [
            array_map(static fn (array $participant): string => $participant['participantId'], $this->keptWithResults),
            array_map(static fn (array $entry): string => $entry['entryId'], $this->keptEntries),
        ];

        return hash('sha256', json_encode($kept, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array{field: string, before: null|string, after: null|string}>
     */
    private function changesOf(PlanPerson $person): array
    {
        $before = $person->before ?? ['name' => null, 'country' => null, 'externalId' => null, 'playerId' => null];
        $changes = [];

        foreach (['name' => 'name', 'country' => 'country', 'externalId' => 'external_id', 'playerId' => 'msp_player_id'] as $property => $field) {
            $old = $before[$property];
            $new = $person->state()[$property];

            if ($old !== $new) {
                $changes[] = ['field' => $field, 'before' => $old, 'after' => $new];
            }
        }

        return $changes;
    }

    /**
     * @return list<string>
     */
    private function roundsRemovedOf(PlanPerson $person): array
    {
        $names = [];
        foreach ($this->entries[$person->key] ?? [] as $entry) {
            if ($entry->removed) {
                $names[] = $this->roundsById[$entry->roundId]->name;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function roundsRestoredOf(PlanPerson $person): array
    {
        $names = [];
        foreach ($this->entries[$person->key] ?? [] as $entry) {
            if (!$entry->isNew() && !$entry->removed) {
                $names[] = $this->roundsById[$entry->roundId]->name;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function roundNamesWithResults(string $playerId): array
    {
        $names = [];
        foreach ($this->site->rounds as $round) {
            if ($this->site->hasResult($playerId, $round->id)) {
                $names[] = $round->name;
            }
        }

        return $names;
    }

    private function entryOf(string $personKey, string $roundId): null|PlanEntry
    {
        $found = null;

        // The last one, like the importer has always done (there should only ever be one)
        foreach ($this->entries[$personKey] ?? [] as $entry) {
            if ($entry->roundId === $roundId) {
                $found = $entry;
            }
        }

        return $found;
    }

    /**
     * D16: a team is its round + its name. One team of that name → it; none → a new one (shared by every row naming
     * it); several → not guessed, how many there are.
     */
    private function resolveTeam(ParticipantImportRound $round, string $teamName): string|int
    {
        $key = self::teamKey($teamName);
        $existing = $this->teamsByName[$round->id][$key] ?? [];

        if (count($existing) === 1) {
            return 't:' . $existing[0];
        }

        if (count($existing) > 1) {
            return count($existing);
        }

        $newKey = 'n:' . $round->id . ':' . $key;
        $this->newTeams[$newKey] ??= ['roundId' => $round->id, 'name' => $teamName];

        return $newKey;
    }

    private function teamName(null|string $teamKey): null|string
    {
        if ($teamKey === null) {
            return null;
        }

        if (str_starts_with($teamKey, 't:')) {
            return $this->teams[substr($teamKey, 2)]['name'] ?? null;
        }

        return $this->newTeams[$teamKey]['name'] ?? null;
    }

    /**
     * @return null|string|list<string> the participant key, null for nobody, or the participants sharing the name (never guessed)
     */
    private function findMatch(null|string $playerId, null|string $externalId, string $name, null|string $country): null|string|array
    {
        // Priority 1: match by msp_player_id
        if ($playerId !== null) {
            foreach ($this->pool as $row) {
                if ($row['player_id'] === $playerId) {
                    return $row['id'];
                }
            }
        }

        // Priority 2: match by external_id
        if ($externalId !== null) {
            foreach ($this->pool as $row) {
                if ($row['external_id'] !== null && $row['external_id'] === $externalId) {
                    return $row['id'];
                }
            }
        }

        // Priority 3: name + country, then the name alone. Somebody with another external id or another linked
        // player is another person of the same name, never this row.
        $sameName = array_filter(
            $this->pool,
            static fn (array $row): bool => $row['name'] === $name
                && ($externalId === null || $row['external_id'] === null || $row['external_id'] === $externalId)
                && ($playerId === null || $row['player_id'] === null || $playerId === $row['player_id']),
        );

        if ($country !== null) {
            $sameNameAndCountry = array_filter($sameName, static fn (array $row): bool => $row['country'] === $country);

            if ($sameNameAndCountry !== []) {
                return self::single($sameNameAndCountry);
            }
        }

        return $sameName === [] ? null : self::single($sameName);
    }

    /**
     * D17 (b): no participant has the row's exact name - exactly one active participant with the same name key
     * (and the same constraints as the name match) is them.
     */
    private function findMatchByNameKey(null|string $playerId, null|string $externalId, string $name, null|string $country): null|string
    {
        $key = ParticipantNameKey::of($name);

        $candidates = array_filter(
            $this->pool,
            fn (array $row): bool => $row['deleted'] === false
                && $this->nameKeys[$row['id']] === $key
                && ($externalId === null || $row['external_id'] === null || $row['external_id'] === $externalId)
                && ($playerId === null || $row['player_id'] === null || $playerId === $row['player_id']),
        );

        if ($country !== null) {
            $sameCountry = array_filter($candidates, static fn (array $row): bool => $row['country'] === $country);

            if ($sameCountry !== []) {
                $candidates = $sameCountry;
            }
        }

        return count($candidates) === 1 ? (string) array_key_first($candidates) : null;
    }

    /**
     * One candidate, or the only active one among them - otherwise all of them.
     *
     * @param non-empty-array<array{id: string, deleted: bool, ...}> $candidates
     * @return string|list<string>
     */
    private static function single(array $candidates): string|array
    {
        $active = array_filter($candidates, static fn (array $row): bool => $row['deleted'] === false);
        $pool = $active !== [] ? $active : $candidates;

        return count($pool) === 1
            ? reset($pool)['id']
            : array_values(array_map(static fn (array $row): string => $row['id'], $pool));
    }

    /**
     * @return array{id: string, name: string, country: null|string, external_id: null|string, player_id: null|string, deleted: bool}
     */
    private static function poolRow(PlanPerson $person): array
    {
        return [
            'id' => $person->key,
            'name' => $person->name,
            'country' => $person->country,
            'external_id' => $person->externalId,
            'player_id' => $person->playerId,
            'deleted' => $person->deleted,
        ];
    }

    private function cleanTeamName(string $value, PlanRow $row): null|string
    {
        $teamName = CompetitionTeam::cleanName($value);

        if ($teamName !== null && mb_strlen($teamName) > CompetitionTeam::NAME_MAX_LENGTH) {
            $row->messages[] = self::message('team_name_too_long', ['%row%' => $row->rowNumber, '%max%' => CompetitionTeam::NAME_MAX_LENGTH]);

            return null;
        }

        return $teamName;
    }

    /**
     * A `round_names` cell: round names separated by commas or semicolons. A round whose own name
     * contains a comma or semicolon is recognised too - the longest run of pieces that is a round's
     * name wins. Pieces matching no round are returned as written, to be reported.
     *
     * @return list<string>
     */
    private function splitRoundNames(string $cell): array
    {
        if (trim($cell) === '') {
            return [];
        }

        /** @var list<string> $parts pieces and the separators between them: piece, separator, piece, ... */
        $parts = preg_split('/([,;])/', $cell, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$cell];
        $pieceCount = intdiv(count($parts) + 1, 2);

        $names = [];
        $i = 0;
        while ($i < $pieceCount) {
            $taken = 1;
            $name = trim($parts[$i * 2]);

            for ($j = $pieceCount - 1; $j > $i; $j--) {
                $candidate = trim(implode('', array_slice($parts, $i * 2, ($j - $i) * 2 + 1)));

                if (isset($this->roundsByKey[self::roundKey($candidate)])) {
                    $taken = $j - $i + 1;
                    $name = $candidate;
                    break;
                }
            }

            if ($name !== '') {
                $names[] = $name;
            }

            $i += $taken;
        }

        return $names;
    }

    /**
     * Round names match ignoring case and repeated whitespace (also around separators) - "team relay" is "Team Relay".
     */
    public static function roundKey(string $name): string
    {
        $name = (string) preg_replace('/\s*([,;])\s*/u', '$1 ', $name);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * Team names match ignoring case and repeated whitespace (D16).
     */
    private static function teamKey(string $name): string
    {
        return mb_strtolower((string) CompetitionTeam::cleanName($name));
    }

    private static function sameTeamName(string $a, null|string $b): bool
    {
        return $b !== null && self::teamKey($a) === self::teamKey($b);
    }

    /**
     * @param list<int> $rowNumbers
     */
    private static function listRowNumbers(array $rowNumbers): string
    {
        $shown = array_slice($rowNumbers, 0, 10);
        $list = implode(', ', $shown);

        if (count($rowNumbers) > count($shown)) {
            $list .= ', …';
        }

        return $list;
    }

    /**
     * @param array<string> $values
     */
    public static function quotedList(array $values): string
    {
        return '"' . implode('", "', $values) . '"';
    }

    /**
     * @param array<string, string|int|TranslatableMessage> $parameters
     */
    public static function message(string $key, array $parameters = []): TranslatableMessage
    {
        return new TranslatableMessage(self::MESSAGE_PREFIX . $key, $parameters);
    }
}
