<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport\Plan;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Results\ParticipantImportRemovals;
use SpeedPuzzling\Web\Results\ParticipantImportRow;
use SpeedPuzzling\Web\Results\ParticipantImportRowProfile;
use SpeedPuzzling\Web\Results\RoundDivision;
use SpeedPuzzling\Web\Value\ColumnMapping;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\DivisionMatch;
use SpeedPuzzling\Web\Value\ParticipantEmail;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRound;
use SpeedPuzzling\Web\Value\ParticipantImportRowAction;
use SpeedPuzzling\Web\Value\ParticipantImportRowData;
use SpeedPuzzling\Web\Value\ParticipantImportRows;
use SpeedPuzzling\Web\Value\ParticipantLinkMethod;
use SpeedPuzzling\Web\Value\PlayerReference;
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
 * - divisions (shared-puzzles-and-divisions.md §3.5): a Division value goes to every entry the row has (or creates) in a
 *   round that uses it - a round of the row that does not is a warning, never another division; `division_in_round`
 *   to that round only; empty leaves the division as it is; a value naming no division of the event is an error of the
 *   row (the rest of the row is imported); a pair/team gets the division its members' rows agree on (they disagree →
 *   a warning, the team keeps its own)
 * - team rows (participant-import-preview.md "As built: team rows"): a row of member columns is one person per filled
 *   member cell, planned as rows of their own (so matching is today's) in the row's team - its Team cell, else
 *   "Team of <first member>" - and rounds (the round columns, the ticket type's round); more members than the round's
 *   team size, or a person in two team rows of one round, keeps the whole row out (and full sync off), fewer is a
 *   warning; a ticket type without a round is an error of the plan
 *
 * The rules the participants sheet follows too - one linked player per active participant, the usual team size, the
 * results guard - live in ParticipantRules and SiteSnapshot, so a file and the sheet never disagree.
 */
final class PlanBuilder
{
    public const string MESSAGE_PREFIX = 'competition.participants.import.';

    /** A filled player cell that is no id, #code or profile link (PlayerReference) */
    private const string REFERENCE_INVALID = 'invalid';

    /** @var array<string, ParticipantImportRound> */
    private array $roundsById = [];

    /** @var array<string, ParticipantImportRound> roundKey() => round, the first of rounds the import cannot tell apart */
    private array $roundsByKey = [];

    /** @var list<non-empty-list<string>> */
    private array $roundCollisions = [];

    /** @var array<string, array{id: string, roundId: string, name: null|string, divisionId?: null|string}> */
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

    /**
     * Removed self-joined participants - the player's own "I left" record: never matched, never a second record
     *
     * @var array<string, PlanPerson>
     */
    private array $selfJoinedLeft = [];

    /** @var list<string> participant ids a `status = deleted` row would remove, kept because of results (D11) */
    private array $keptByStatus = [];

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

    /** @var list<string> team ids full sync would delete as emptied, kept because they hold an official result */
    private array $keptTeams = [];

    /** @var list<array{entryId: string, participantName: string, roundName: string, teamName: null|string}> */
    private array $removedEntries = [];

    /** @var list<array{entryId: string, participantName: string, roundName: string}> */
    private array $keptEntries = [];

    /** @var list<array{participantName: string, roundName: string, from: null|string, to: null|string}> */
    private array $teamChanges = [];

    /** @var list<array{teamId: string, roundName: string, teamName: null|string, activeMembers: int}> */
    private array $deletedTeams = [];

    /**
     * Rows with a filled player cell: the cell as written and the player it names (participant-linking.md §7.3, D7)
     *
     * @var array<int, array{cell: string, status: string, playerId: null|string}>
     */
    private array $playerReferences = [];

    /** @var array<int, list<int>> row number => every row naming the same player for another person (D7: none links) */
    private array $playerInFileTwice = [];

    /** @var array<string, array<string, true>> participant id => player ids refused for it (participant-linking.md D11) */
    private array $refusedPairs = [];

    /** @var array<string, array<string, list<array{division: RoundDivision, row: int}>>> person key => round id => the divisions the file gives the entry */
    private array $divisionWishes = [];

    /** @var array<string, string> team key ("t:…" / "n:…") => the division the import gives the pair/team */
    private array $teamDivisionChanges = [];

    /** @var array<int, int> planning row number => the file's row, for the people of team rows (ColumnMapping::MEMBER_ROW_FACTOR) */
    private array $fileRowOf = [];

    /** @var array<int, string> team row => its first member's name ("Team of <name>" when the row names no team) */
    private array $teamLead = [];

    /** @var array<int, int> team row => the planning row of its first person - the row the team's messages are on */
    private array $firstMemberRow = [];

    /** @var array<int, array{name: null|string, rounds: list<string>}> team row => its team and the rounds it is in */
    private array $teamRowBlocks = [];

    /** @var array<int, list<TranslatableMessage>> team row => what the organiser should know (it is imported) */
    private array $teamRowMessages = [];

    /** @var array<int, list<TranslatableMessage>> team row => why nothing of it is imported */
    private array $blockedTeamRows = [];

    /** @var array<string, list<int>> ticket type as written => file rows - nobody said which round it means */
    private array $ticketsWithoutRound = [];

    /** @var array<int, TranslatableMessage> row of a file of people => why it is not imported (its ticket type is skipped) */
    private array $skippedTicketRows = [];

    /** @var list<TranslatableMessage> nothing can be imported while there are any */
    private array $errors = [];

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
                connectedVia: $participant['connectedVia'] ?? null,
                playerCode: $participant['playerCode'] ?? null,
                playerMasked: $participant['playerMasked'] ?? false,
                email: $participant['email'] ?? null,
            );
            $person->before = $person->state();
            $this->people[$person->key] = $person;

            foreach ($participant['refusedPlayerIds'] ?? [] as $refusedPlayerId) {
                $this->refusedPairs[$participant['id']][$refusedPlayerId] = true;
            }

            // A player's own "I left" record is not the organiser's data: restoring it would sign them up again
            if ($participant['selfJoined'] && $participant['deletedAt'] !== null) {
                $this->selfJoinedLeft[$person->key] = $person;
            } else {
                $this->pool[$person->key] = self::poolRow($person);
                $this->nameKeys[$person->key] = ParticipantNameKey::of($person->name);
            }
        }

        foreach ($site->entries as $entry) {
            $team = $entry['teamId'] !== null ? 't:' . $entry['teamId'] : null;
            $this->entries[$entry['participantId']][] = new PlanEntry($entry['id'], $entry['participantId'], $entry['roundId'], $team, $team, originalDivision: $entry['divisionId'] ?? null);
        }

        foreach ($file->rows as $row) {
            if ($row->teamRow !== null) {
                $this->fileRowOf[$row->rowNumber] = $row->teamRow;
            }

            foreach (array_keys($row->teamsByRound) as $roundId) {
                if (isset($this->roundsById[$roundId]) && $this->roundsById[$roundId]->hasTeams()) {
                    $this->ownTeamColumnRounds[$roundId] = true;
                }
            }
        }
    }

    public function build(): BuiltPlan
    {
        $this->resolvePlayerReferences();
        $this->planTeamRows();

        foreach ($this->file->rows as $row) {
            if ($row->teamRow !== null && isset($this->blockedTeamRows[$row->teamRow])) {
                $this->skipBlockedTeamMember($row);

                continue;
            }

            if (isset($this->skippedTicketRows[$row->rowNumber])) {
                $planRow = new PlanRow($row->rowNumber, $row->name);
                $planRow->messages[] = $this->skippedTicketRows[$row->rowNumber];
                $this->rows[$row->rowNumber] = $planRow;

                continue;
            }

            $this->planRow($row);

            if ($row->teamRow !== null && ($this->firstMemberRow[$row->teamRow] ?? null) === $row->rowNumber) {
                foreach ($this->teamRowMessages[$row->teamRow] ?? [] as $message) {
                    $this->rows[$row->rowNumber]->messages[] = $message;
                }
            }
        }

        $this->planRoundsAndTeams();

        if ($this->mode === ParticipantImportMode::Sync) {
            $this->planRemovals();
        }

        $this->planDivisions();

        $this->warnAboutTeamSizes();
        $this->warnAboutNames();

        return $this->result();
    }

    private function planRow(ParticipantImportRowData $data): void
    {
        $rowNum = $data->rowNumber;
        $row = new PlanRow($rowNum, $data->name);
        $row->teamRow = $data->teamRow;
        $row->member = $data->member;
        $this->rows[$rowNum] = $row;

        $name = $data->name;
        if ($name === '') {
            $row->messages[] = self::message('missing_name', ['%row%' => $rowNum]);
            $this->missingNameRows[] = $rowNum;

            return;
        }

        $country = $data->country ?? '';
        $externalId = $data->externalId ?? '';
        $status = strtolower($data->status ?? '');
        $participantIdCell = $data->participantId ?? '';

        $countryCode = null;
        if ($country !== '') {
            $countryCode = CountryCode::fromCode($country);
            if ($countryCode === null) {
                $row->messages[] = self::message('invalid_country', ['%row%' => $rowNum, '%country%' => $country]);
            }
        }

        // The organiser's address of the person (participant-linking.md §7.3): set when the cell is filled, an empty cell
        // keeps the one on the site, a cell that is no address is reported and the row imported without it
        $email = null;
        if ($data->email !== null && $data->email !== '') {
            $email = ParticipantEmail::read($data->email);

            if ($email === false) {
                $row->messages[] = self::message('invalid_email', ['%row%' => $rowNum, '%email%' => $data->email]);
                $email = null;
            }
        }

        $validPlayerId = null;
        $reference = $this->playerReferences[$rowNum] ?? null;
        if ($reference !== null) {
            $parameters = ['%row%' => $rowNum, '%reference%' => $reference['cell']];

            if ($reference['status'] === self::REFERENCE_INVALID) {
                $row->messages[] = self::message('linking.invalid_player_reference', $parameters);
            } elseif ($reference['status'] === SiteSnapshot::REFERENCE_UNKNOWN) {
                $row->messages[] = self::message('linking.unknown_player_reference', $parameters);
            } elseif ($reference['status'] === SiteSnapshot::REFERENCE_CODE_CHANGED_OWNER) {
                $row->messages[] = self::message('linking.code_changed_owner', $parameters);
            } elseif (isset($this->playerInFileTwice[$rowNum])) {
                $row->messages[] = self::message('linking.player_in_file_twice', $parameters + [
                    '%rows%' => implode(', ', $this->fileRows($this->playerInFileTwice[$rowNum])),
                ]);
            } else {
                $validPlayerId = $reference['playerId'];
            }
        }

        // Priority 0: the participant's own id (the export writes it)
        $matchedKey = null;
        $byNameKey = false;
        if ($participantIdCell !== '') {
            $normalisedId = strtolower($participantIdCell);

            if (Uuid::isValid($normalisedId) && isset($this->pool[$normalisedId])) {
                $matchedKey = $normalisedId;
            } elseif (isset($this->selfJoinedLeft[$normalisedId])) {
                $this->skipSelfJoinedLeft($row, $this->selfJoinedLeft[$normalisedId]);

                return;
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

            // Nobody else - but a player who signed up by themselves and left: never a second record of them
            if ($matchedKey === null) {
                $left = $this->findSelfJoinedLeft($validPlayerId, $name);

                if ($left !== null) {
                    $this->skipSelfJoinedLeft($row, $left);

                    return;
                }
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
            if ($email !== null) {
                $person->email = $email;
            }
            // A row never takes over a link the participant has (somebody else's participant_id, a typo): a matched
            // participant keeps their player, and keeps their external id when another participant of the event
            // has the file's one - otherwise the organiser's own id is theirs to correct (as before the preview)
            if ($externalId !== '' && $externalId !== $person->externalId) {
                if ($this->externalIdTakenByAnother($externalId, $person->key)) {
                    $row->messages[] = self::message('external_id_kept', [
                        '%row%' => $rowNum,
                        '%name%' => $name,
                        '%current%' => $person->externalId ?? '-',
                        '%id%' => $externalId,
                    ]);
                } else {
                    $person->externalId = $externalId;
                }
            }
            if ($validPlayerId !== null && $validPlayerId !== $person->playerId) {
                $cell = $reference['cell'];

                if ($person->playerId !== null) {
                    // The player's own link (self-join, a claim, a personal link) - the file says otherwise, the
                    // organiser should know who made the link they cannot replace
                    $linkedByThemselves = $person->playerId === ($person->before['playerId'] ?? null)
                        && ParticipantLinkMethod::tryFrom((string) $person->connectedVia)?->isThePlayersOwn() === true;

                    $row->messages[] = self::message($linkedByThemselves ? 'linking.player_id_kept_self' : 'player_id_kept', ['%row%' => $rowNum, '%name%' => $name, '%id%' => $cell]);
                } elseif ($this->canConnect($validPlayerId, $person->key, $row, $name, $cell)) {
                    $person->playerId = $validPlayerId;
                    $person->linkedByReference = $cell;
                    $this->warnAboutProfileName($row, $name, $validPlayerId, $cell);
                }
            }
            $person->deleted = false;
        } else {
            $cell = $reference !== null ? $reference['cell'] : (string) $validPlayerId;
            $person = new PlanPerson(
                key: 'new:' . $rowNum,
                id: null,
                name: $name,
                country: $countryCode?->name,
                externalId: $externalId !== '' ? $externalId : null,
                playerId: $validPlayerId !== null && $this->canConnect($validPlayerId, 'new:' . $rowNum, $row, $name, $cell) ? $validPlayerId : null,
                deleted: false,
                email: $email,
            );
            $this->people[$person->key] = $person;

            if ($person->playerId !== null) {
                $person->linkedByReference = $cell;
                $this->warnAboutProfileName($row, $name, $person->playerId, $cell);
            }
        }

        $person->inFile = true;
        $person->rows[] = $rowNum;
        $row->personKey = $person->key;

        if ($status === 'deleted') {
            // Somebody with results in this event stays (D11), as full sync keeps them
            if ($person->wasActive() && $this->hasResult($person)) {
                $row->messages[] = self::message('deleted_kept_with_results', ['%row%' => $rowNum, '%name%' => $name]);
                $this->keptByStatus[] = $person->key;
            } else {
                $person->deleted = true;
                $person->deletedByFile = true;
            }
        }

        // Later rows match what this row made of the participant (a rename, a new external id, ...)
        $this->pool[$person->key] = self::poolRow($person);
        $this->nameKeys[$person->key] = ParticipantNameKey::of($person->name);

        // A participant removed by the file gets no rounds
        if ($status === 'deleted') {
            return;
        }

        $teamName = $this->cleanTeamName($data->team ?? '', $row);

        // A team row naming no team: the team is called after its first member
        if ($teamName === null && $data->teamRow !== null && trim($data->team ?? '') === '' && isset($this->teamLead[$data->teamRow])) {
            $teamName = self::generatedTeamName($this->teamLead[$data->teamRow]);
        }

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

        // The round the organiser chose for the row's ticket type
        $ticketRound = $data->ticketRound !== null ? ($this->roundsById[$data->ticketRound] ?? null) : null;
        if ($ticketRound !== null) {
            $rowRounds[$ticketRound->id] = $ticketRound;
            $person->roundsListed[$ticketRound->id] = true;
            $person->listsRounds = true;
        }

        $this->collectDivisions($data, $person, $row, $rowRounds);

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
     * The team rows of the file, before any of their people is planned: the ticket type's round, the number of members
     * against the round's team size, one person in two team rows of a round (by the name key - the people are matched
     * only later). A row with a problem is kept out whole (blockedTeamRows), never half a team.
     */
    private function planTeamRows(): void
    {
        /** @var array<int, non-empty-list<ParticipantImportRowData>> $groups */
        $groups = [];
        foreach ($this->file->rows as $row) {
            if ($row->teamRow !== null) {
                $groups[$row->teamRow][] = $row;
            } elseif ($row->ticketType !== null && $row->ticketType !== '') {
                // A file of people with a Ticket type column: the same choices, per row
                if ($row->ticketRound === ColumnMapping::TICKET_SKIP) {
                    $this->skippedTicketRows[$row->rowNumber] = self::teamMessage('plan.ticket_skipped', ['%row%' => $row->rowNumber, '%ticket%' => $row->ticketType]);
                } elseif ($row->ticketRound === null || !isset($this->roundsById[$row->ticketRound])) {
                    $this->ticketsWithoutRound[$row->ticketType][] = $row->rowNumber;
                }
            }
        }

        /** @var array<string, array<string, array<int, string>>> $teamsOfPerson name key => round id => team row => name */
        $teamsOfPerson = [];

        foreach ($groups as $teamRow => $members) {
            $first = $members[0];
            $this->firstMemberRow[$teamRow] = $first->rowNumber;

            $people = array_values(array_filter($members, static fn (ParticipantImportRowData $member): bool => $member->name !== ''));
            if ($people !== []) {
                $this->teamLead[$teamRow] = $people[0]->name;
            }

            $rounds = $this->roundsOfTeamRow($first);
            $teamName = CompetitionTeam::cleanName($first->team) ?? (isset($this->teamLead[$teamRow]) ? self::generatedTeamName($this->teamLead[$teamRow]) : null);
            $this->teamRowBlocks[$teamRow] = [
                'name' => array_filter($rounds, static fn (ParticipantImportRound $round): bool => $round->hasTeams()) !== [] ? $teamName : null,
                'rounds' => array_values(array_map(static fn (ParticipantImportRound $round): string => $round->name, $rounds)),
            ];

            $ticket = $first->ticketType;
            if ($ticket !== null && $ticket !== '') {
                if ($first->ticketRound === ColumnMapping::TICKET_SKIP) {
                    $this->blockedTeamRows[$teamRow][] = self::teamMessage('plan.ticket_skipped', ['%row%' => $first->rowNumber, '%ticket%' => $ticket]);

                    continue;
                }

                if ($first->ticketRound === null || !isset($this->roundsById[$first->ticketRound])) {
                    $this->ticketsWithoutRound[$ticket][] = $teamRow;
                }
            }

            $count = count($people);
            foreach ($rounds as $round) {
                $size = $round->category === RoundCategory::Duo ? 2 : ($round->category === RoundCategory::Team ? $round->teamSize : null);

                if ($size === null || $count === 0) {
                    continue;
                }

                $parameters = ['%row%' => $first->rowNumber, '%team%' => (string) $teamName, '%round%' => $round->name, '%count%' => $count, '%size%' => $size];

                if ($count > $size) {
                    $this->blockedTeamRows[$teamRow][] = self::teamMessage('plan.too_many_members', $parameters);
                } elseif ($count < $size) {
                    $this->teamRowMessages[$teamRow][] = self::teamMessage('plan.too_few_members', $parameters);
                }
            }

            foreach ($people as $person) {
                foreach (array_keys($rounds) as $roundId) {
                    $teamsOfPerson[ParticipantNameKey::of($person->name)][$roundId][$teamRow] = $person->name;
                }
            }
        }

        foreach ($teamsOfPerson as $byRound) {
            foreach ($byRound as $roundId => $teamRows) {
                if (count($teamRows) < 2) {
                    continue;
                }

                $firstRows = array_map(fn (int $teamRow): int => $this->firstMemberRow[$teamRow], array_keys($teamRows));
                foreach ($teamRows as $teamRow => $name) {
                    $this->blockedTeamRows[$teamRow][] = self::teamMessage('plan.person_in_two_teams', [
                        '%row%' => $this->firstMemberRow[$teamRow],
                        '%name%' => $name,
                        '%round%' => $this->roundsById[$roundId]->name,
                        '%rows%' => $this->listRowNumbers($firstRows),
                    ]);
                }
            }
        }

        foreach ($this->ticketsWithoutRound as $ticket => $teamRows) {
            $this->errors[] = self::teamMessage('plan.ticket_without_round', [
                '%ticket%' => (string) $ticket,
                '%count%' => count($teamRows),
                '%rows%' => self::listFileRows($teamRows),
            ]);
        }
    }

    /**
     * A person of a team row kept out: the row is shown, nothing of it is planned. The reasons are on its first person.
     */
    private function skipBlockedTeamMember(ParticipantImportRowData $data): void
    {
        $row = new PlanRow($data->rowNumber, $data->name);
        $row->teamRow = $data->teamRow;
        $row->member = $data->member;
        $this->rows[$data->rowNumber] = $row;

        if ($data->teamRow !== null && ($this->firstMemberRow[$data->teamRow] ?? null) === $data->rowNumber) {
            $row->messages = $this->blockedTeamRows[$data->teamRow];
        }
    }

    /**
     * The rounds a team row names - its round columns (unknown names are reported by planRow()) and its ticket type's
     * round. Reads only.
     *
     * @return array<string, ParticipantImportRound>
     */
    private function roundsOfTeamRow(ParticipantImportRowData $data): array
    {
        $names = $data->roundNames !== null ? $this->splitRoundNames($data->roundNames) : [];
        if ($data->roundName !== null && $data->roundName !== '') {
            $names[] = $data->roundName;
        }

        $rounds = [];
        foreach ($names as $name) {
            $round = $this->roundsByKey[self::roundKey($name)] ?? null;

            if ($round !== null) {
                $rounds[$round->id] = $round;
            }
        }

        if ($data->ticketRound !== null && isset($this->roundsById[$data->ticketRound])) {
            $rounds[$data->ticketRound] = $this->roundsById[$data->ticketRound];
        }

        return $rounds;
    }

    private static function generatedTeamName(string $firstMember): string
    {
        return mb_substr('Team of ' . $firstMember, 0, CompetitionTeam::NAME_MAX_LENGTH);
    }

    /**
     * Planning row numbers as the organiser sees them: a team row's people are its row (once).
     *
     * @param list<int> $rowNumbers
     * @return list<int>
     */
    private function fileRows(array $rowNumbers): array
    {
        if ($this->fileRowOf === []) {
            return $rowNumbers;
        }

        return array_values(array_unique(array_map(fn (int $rowNumber): int => $this->fileRowOf[$rowNumber] ?? $rowNumber, $rowNumbers)));
    }

    /**
     * @param list<int> $fileRows
     */
    private static function listFileRows(array $fileRows): string
    {
        $shown = array_slice($fileRows, 0, 10);

        return implode(', ', $shown) . (count($fileRows) > count($shown) ? ', …' : '');
    }

    /**
     * A message naming a person's planning row (`%row%`) names the file's row of their team row instead.
     */
    private function relabel(TranslatableMessage $message): TranslatableMessage
    {
        $parameters = $message->getParameters();
        $row = $parameters['%row%'] ?? null;

        if (!is_int($row) || !isset($this->fileRowOf[$row])) {
            return $message;
        }

        $parameters['%row%'] = $this->fileRowOf[$row];

        return new TranslatableMessage($message->getMessage(), $parameters, $message->getDomain());
    }

    /**
     * @param list<TranslatableMessage> $messages
     * @return list<TranslatableMessage>
     */
    private function relabelAll(array $messages): array
    {
        return $this->fileRowOf === [] ? $messages : array_map($this->relabel(...), $messages);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private static function teamMessage(string $key, array $parameters): TranslatableMessage
    {
        return new TranslatableMessage('team_tickets.' . $key, $parameters);
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

    /**
     * The divisions the row names (shared-puzzles-and-divisions.md §3.5): the Division cell for every round of the row
     * (the rounds it lists, else the rounds the person is in) that uses it, a round's own Division cell for that round.
     * Empty = leave as it is. Applied after the entries and teams are planned (planDivisions()).
     *
     * @param array<string, ParticipantImportRound> $rowRounds
     */
    private function collectDivisions(ParticipantImportRowData $data, PlanPerson $person, PlanRow $row, array $rowRounds): void
    {
        $asked = [];

        if ($data->division !== null && !DivisionMatch::isBlank($data->division)) {
            $rounds = $rowRounds;

            if ($rounds === []) {
                foreach ($this->entries[$person->key] ?? [] as $entry) {
                    $rounds[$entry->roundId] = $this->roundsById[$entry->roundId];
                }
            }

            if ($rounds === []) {
                $row->messages[] = self::divisionMessage('no_round', ['%row%' => $row->rowNumber, '%name%' => $person->name, '%division%' => $data->division]);
            }

            foreach (array_keys($rounds) as $roundId) {
                $asked[$roundId] = trim($data->division);
            }
        }

        foreach ($data->divisionsByRound as $roundId => $cell) {
            if (!DivisionMatch::isBlank($cell) && isset($this->roundsById[$roundId])) {
                $asked[$roundId] = trim($cell);
            }
        }

        $unknown = [];
        foreach ($asked as $roundId => $value) {
            $round = $this->roundsById[$roundId];
            $division = ParticipantRules::divisionInRound($value, $round, $this->site->divisions);

            if ($division === DivisionMatch::UNKNOWN_DIVISION) {
                $unknown[DivisionMatch::key($value)] = $value;
            } elseif ($division === DivisionMatch::NOT_IN_ROUND) {
                $row->messages[] = self::divisionMessage('not_in_round', [
                    '%row%' => $row->rowNumber,
                    '%round%' => $round->name,
                    '%division%' => DivisionMatch::ofEvent($value, $this->site->divisions)->name ?? $value,
                ]);
            } elseif ($division instanceof RoundDivision) {
                $this->divisionWishes[$person->key][$roundId][] = ['division' => $division, 'row' => $row->rowNumber];
            }
        }

        foreach ($unknown as $value) {
            $row->messages[] = self::divisionMessage('unknown', [
                '%row%' => $row->rowNumber,
                '%value%' => $value,
                '%divisions%' => implode(', ', array_map(static fn (RoundDivision $division): string => $division->name, $this->site->divisions)),
            ]);
        }
    }

    /**
     * The divisions the file gives, on the entries and pairs/teams as planned: a solo entry gets its own, a pair/team the
     * one its members' rows agree on.
     */
    private function planDivisions(): void
    {
        /** @var array<string, array{round: ParticipantImportRound, divisions: array<string, RoundDivision>, rows: list<int>, people: list<string>}> $teamWishes */
        $teamWishes = [];
        $deletedTeams = array_fill_keys(array_map(static fn (array $team): string => 't:' . $team['teamId'], $this->deletedTeams), true);

        foreach ($this->divisionWishes as $personKey => $byRound) {
            $person = $this->people[$personKey];

            foreach ($byRound as $roundId => $wishes) {
                $round = $this->roundsById[$roundId];
                $firstRow = $this->rows[$wishes[0]['row']];
                $divisions = [];
                foreach ($wishes as $wish) {
                    $divisions[$wish['division']->id] = $wish['division'];
                }
                $division = $wishes[0]['division'];
                $entry = $this->entryOf($personKey, $roundId);

                if ($person->deleted || $entry === null || $entry->removed) {
                    $firstRow->messages[] = self::divisionMessage('not_entered', ['%row%' => $firstRow->rowNumber, '%name%' => $person->name, '%round%' => $round->name, '%division%' => $division->name]);

                    continue;
                }

                if (count($divisions) > 1) {
                    $firstRow->messages[] = self::divisionMessage('conflict_in_file', ['%name%' => $person->name, '%round%' => $round->name]);

                    continue;
                }

                if (!$round->hasTeams()) {
                    if ($entry->division !== $division->id) {
                        $before = $entry->division;
                        $entry->division = $division->id;
                        $this->entriesChanged[$personKey] = true;

                        foreach (array_unique(array_column($wishes, 'row')) as $rowNumber) {
                            $this->rows[$rowNumber]->divisionChanges[$round->name] = ['before' => $this->divisionName($round, $before), 'after' => $division->name];
                        }
                    }

                    continue;
                }

                if ($entry->team === null || isset($deletedTeams[$entry->team])) {
                    $firstRow->messages[] = self::divisionMessage('no_team', ['%row%' => $firstRow->rowNumber, '%name%' => $person->name, '%round%' => $round->name]);

                    continue;
                }

                $teamWishes[$entry->team] ??= ['round' => $round, 'divisions' => [], 'rows' => [], 'people' => []];
                $teamWishes[$entry->team]['divisions'][$division->id] = $division;
                $teamWishes[$entry->team]['rows'] = [...$teamWishes[$entry->team]['rows'], ...array_column($wishes, 'row')];
                $teamWishes[$entry->team]['people'][] = $personKey;
            }
        }

        foreach ($teamWishes as $teamKey => $wish) {
            $round = $wish['round'];
            $rows = array_values(array_unique($wish['rows']));

            if (count($wish['divisions']) > 1) {
                $this->rows[$rows[0]]->messages[] = self::divisionMessage('members_disagree', ['%team%' => $this->teamName($teamKey) ?? '', '%round%' => $round->name]);

                continue;
            }

            $division = array_values($wish['divisions'])[0];
            $current = $this->originalTeamDivision($teamKey);

            if ($current === $division->id) {
                continue;
            }

            $this->teamDivisionChanges[$teamKey] = $division->id;

            foreach ($wish['people'] as $personKey) {
                $this->entriesChanged[$personKey] = true;
            }

            foreach ($rows as $rowNumber) {
                $this->rows[$rowNumber]->divisionChanges[$round->name] = ['before' => $this->divisionName($round, $current), 'after' => $division->name];
            }
        }
    }

    private function originalTeamDivision(string $teamKey): null|string
    {
        return str_starts_with($teamKey, 't:') ? ($this->teams[substr($teamKey, 2)]['divisionId'] ?? null) : null;
    }

    private function divisionName(ParticipantImportRound $round, null|string $divisionId): null|string
    {
        if ($divisionId === null) {
            return null;
        }

        return $round->division($divisionId)->name
            ?? (array_values(array_filter($this->site->divisions, static fn (RoundDivision $division): bool => $division->id === $divisionId))[0] ?? null)?->name;
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private static function divisionMessage(string $key, array $parameters): TranslatableMessage
    {
        return new TranslatableMessage('entry_divisions.import.' . $key, $parameters);
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

                    if ($this->hasResult($person, $entry->roundId)) {
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

            if ($this->hasResult($person)) {
                $this->keptWithResults[] = [
                    'participantId' => $participant['id'],
                    'name' => $person->name,
                    'rounds' => $this->roundNamesWithResults($person),
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

            // A pair/team holding an official result (or a qualified mark) stays, even emptied - its result is the
            // organiser's record (official-results.md, guards)
            if ($this->site->teamHasOfficialResult((string) $teamId)) {
                $this->keptTeams[] = (string) $teamId;
                $this->warnings[] = self::message('warning.team_kept_with_official_result', [
                    '%team%' => $team['name'] ?? '–',
                    '%round%' => $this->roundsById[$team['roundId']]->name,
                ]);

                continue;
            }
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
     * D16 (c): more people under one name than the round's teams usually have (a team round's expected size when the
     * organiser set one), and pairs/teams of one person - only teams somebody of the file is in.
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
            // The organiser's expected size of a team round when they set one (D5), else the guess
            $usual = $round->category === RoundCategory::Duo ? 2 : ($round->teamSize ?? $this->usualTeamSize($round, $teamKeys, $members));

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

        // The most common size; on a tie the smaller one (the sheet's guess too)
        return ParticipantRules::usualTeamSize($sizes);
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
                '%rows%' => $this->listRowNumbers($rows),
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
                'email' => $person->email,
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
        $entryDivisions = [];
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
                    if ($entry->division !== null) {
                        $entryDivisions[] = ['participantKey' => $personKey, 'roundId' => $entry->roundId, 'division' => $entry->division];
                    }
                } elseif ($entry->removed) {
                    $deletedEntries[] = (string) $entry->id;
                } else {
                    if ($entry->team !== $entry->originalTeam) {
                        $entryTeams[] = ['entryId' => (string) $entry->id, 'team' => $entry->team];
                    }
                    if ($entry->division !== $entry->originalDivision) {
                        $entryDivisions[] = ['entryId' => (string) $entry->id, 'roundId' => $entry->roundId, 'division' => $entry->division];
                    }
                }
            }
        }

        $newTeams = [];
        foreach ($this->newTeams as $key => $team) {
            if (isset($usedNewTeams[$key])) {
                $newTeams[] = ['key' => $key, 'roundId' => $team['roundId'], 'name' => $team['name']];
            }
        }

        $teamDivisions = [];
        foreach ($this->teamDivisionChanges as $teamKey => $divisionId) {
            if (str_starts_with($teamKey, 't:') || isset($usedNewTeams[$teamKey])) {
                $teamDivisions[] = ['team' => $teamKey, 'division' => $divisionId];
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
                    messages: $this->relabelAll($row->messages),
                    teamRow: $row->teamRow,
                    member: $row->member,
                    teamName: $this->teamRowName($row),
                    teamRounds: $this->teamRowRounds($row),
                );

                continue;
            }

            $person = $this->people[$personKey];
            $first = !isset($seenPeople[$personKey]);
            $seenPeople[$personKey] = true;

            $teams = [];
            $teamsBefore = [];
            foreach ($row->teamRoundIds as $roundId) {
                $entry = $this->entryOf($personKey, $roundId);
                $roundName = $this->roundsById[$roundId]->name;
                $teams[$roundName] = $entry !== null && !$entry->removed ? $this->teamName($entry->team) : null;

                // A team the import gives or changes - shown as a change, not like a team line that stays
                if ($entry !== null && !$entry->removed && $entry->team !== $entry->originalTeam) {
                    $teamsBefore[$roundName] = $this->teamName($entry->originalTeam);
                }
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
                teamsBefore: $teamsBefore,
                messages: $this->relabelAll($row->messages),
                removedAt: $first && $person->isRestored() ? $person->deletedAt : null,
                roundsRestored: $first && $person->isRestored() ? $this->roundsRestoredOf($person) : [],
                profile: $this->profileOf($rowNumber, $person, $first),
                divisions: $row->divisionChanges,
                teamRow: $row->teamRow,
                member: $row->member,
                teamName: $this->teamRowName($row),
                teamRounds: $this->teamRowRounds($row),
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
                '%rows%' => $this->listRowNumbers($rowNumbers),
                '%count%' => count($rowNumbers),
                '%rounds%' => $this->site->rounds === []
                    ? self::message('no_rounds_yet')
                    : self::quotedList(array_map(static fn (ParticipantImportRound $round): string => $round->name, array_values($this->roundsByKey))),
            ]);
        }

        return new BuiltPlan(
            rows: $rows,
            warnings: $this->relabelAll([...$warnings, ...$this->warnings]),
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
                entryDivisions: $entryDivisions,
                teamDivisions: $teamDivisions,
            ),
            syncBlockers: $this->relabelAll($this->syncBlockers()),
            activeParticipantsBefore: count(array_filter($this->site->participants, static fn (array $participant): bool => $participant['deletedAt'] === null)),
            resultsGuard: $this->resultsGuard(),
            errors: $this->errors,
        );
    }

    /**
     * The pair/team of the team row a row of the plan belongs to (null for a row per person).
     */
    private function teamRowName(PlanRow $row): null|string
    {
        return $row->teamRow !== null ? ($this->teamRowBlocks[$row->teamRow]['name'] ?? null) : null;
    }

    /**
     * @return list<string> the rounds of the team row a row of the plan belongs to (none for a row per person)
     */
    private function teamRowRounds(PlanRow $row): array
    {
        return $row->teamRow !== null ? ($this->teamRowBlocks[$row->teamRow]['rounds'] ?? []) : [];
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
                '%rows%' => $this->listRowNumbers($this->missingNameRows),
            ]);
        }

        if ($this->ambiguousRows !== []) {
            $blockers[] = self::message('plan.blocker.ambiguous_name', [
                '%count%' => count($this->ambiguousRows),
                '%rows%' => $this->listRowNumbers($this->ambiguousRows),
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

        // A row kept out (too many members, a person in two teams, a skipped ticket type) - full sync would take its
        // people off the event
        if ($this->blockedTeamRows !== [] || $this->skippedTicketRows !== []) {
            $teamRows = [...array_keys($this->blockedTeamRows), ...array_keys($this->skippedTicketRows)];
            sort($teamRows);
            $blockers[] = self::teamMessage('plan.blocker.rows_not_imported', [
                '%count%' => count($teamRows),
                '%rows%' => self::listFileRows($teamRows),
            ]);
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
     * What the plan keeps because of results (full sync's removals, `status = deleted` rows) - part of the fingerprint,
     * so a result arriving after the preview for somebody the preview removed makes the preview stale (D8), while
     * results of everybody else do not.
     */
    private function resultsGuard(): string
    {
        $kept = [
            array_map(static fn (array $participant): string => $participant['participantId'], $this->keptWithResults),
            array_map(static fn (array $entry): string => $entry['entryId'], $this->keptEntries),
            $this->keptByStatus,
            $this->keptTeams,
        ];

        return hash('sha256', json_encode($kept, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array{field: string, before: null|string, after: null|string}>
     */
    private function changesOf(PlanPerson $person): array
    {
        $before = $person->before ?? ['name' => null, 'country' => null, 'externalId' => null, 'playerId' => null, 'email' => null];
        $changes = [];

        foreach (['name' => 'name', 'country' => 'country', 'externalId' => 'external_id', 'playerId' => 'msp_player_id', 'email' => 'email'] as $property => $field) {
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
    private function roundNamesWithResults(PlanPerson $person): array
    {
        $names = [];
        foreach ($this->site->rounds as $round) {
            if ($this->hasResult($person, $round->id)) {
                $names[] = $round->name;
            }
        }

        return $names;
    }

    /**
     * A result the import never takes away (D11): one the player added, or an official result of the participant.
     */
    private function hasResult(PlanPerson $person, null|string $roundId = null): bool
    {
        return $this->site->hasAnyResult($person->playerId, $person->id, $roundId);
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
     * (and the same constraints as the name match) is them. Only participants on the site before the import: two rows
     * of the file written differently stay two people (warned by D17 (a)), never merged by the key.
     */
    private function findMatchByNameKey(null|string $playerId, null|string $externalId, string $name, null|string $country): null|string
    {
        $key = ParticipantNameKey::of($name);

        $candidates = array_filter(
            $this->pool,
            fn (array $row): bool => $row['deleted'] === false
                && !str_starts_with($row['id'], 'new:')
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
     * A removed self-joined participant the row stands for: the same player, or (no player in the row) the same name key.
     */
    private function findSelfJoinedLeft(null|string $playerId, string $name): null|PlanPerson
    {
        $key = ParticipantNameKey::of($name);

        foreach ($this->selfJoinedLeft as $person) {
            if ($playerId !== null ? $person->playerId === $playerId : ParticipantNameKey::of($person->name) === $key) {
                return $person;
            }
        }

        return null;
    }

    private function skipSelfJoinedLeft(PlanRow $row, PlanPerson $person): void
    {
        $row->messages[] = self::message('self_joined_left', [
            '%row%' => $row->rowNumber,
            '%name%' => $person->name,
        ]);
    }

    /**
     * Every row's player cell, resolved through the snapshot (participant-linking.md §7.3). D7: the same player on
     * rows of different people links none of them - never a guess by order. Rows of ONE person (one row per round)
     * add up as always: the same name key, and no two different participant ids.
     */
    private function resolvePlayerReferences(): void
    {
        /** @var array<string, list<ParticipantImportRowData>> $rowsByPlayer */
        $rowsByPlayer = [];

        foreach ($this->file->rows as $data) {
            $cell = trim($data->playerId ?? '');

            if ($cell === '') {
                continue;
            }

            $reference = PlayerReference::parse($cell);
            $resolution = $reference !== null
                ? $this->site->resolve($reference)
                : ['status' => self::REFERENCE_INVALID, 'playerId' => null];

            $this->playerReferences[$data->rowNumber] = ['cell' => $cell] + $resolution;

            if ($resolution['playerId'] !== null && $data->name !== '') {
                $rowsByPlayer[$resolution['playerId']][] = $data;
            }
        }

        foreach ($rowsByPlayer as $rows) {
            if (count($rows) < 2) {
                continue;
            }

            $nameKeys = [];
            $participantIds = [];
            foreach ($rows as $data) {
                $nameKeys[ParticipantNameKey::of($data->name)] = true;

                $participantId = $data->participantId ?? '';
                if ($participantId !== '') {
                    $participantIds[strtolower($participantId)] = true;
                }
            }

            if (count($nameKeys) === 1 && count($participantIds) <= 1) {
                continue;
            }

            $rowNumbers = array_map(static fn (ParticipantImportRowData $data): int => $data->rowNumber, $rows);
            foreach ($rowNumbers as $rowNumber) {
                $this->playerInFileTwice[$rowNumber] = $rowNumbers;
            }
        }
    }

    /**
     * R5: the file's name and the profile's name are not the same name key - linked anyway (the organiser decides),
     * but said. Never for a profile the organiser may not see: the warning would tell them whether a guessed name is
     * the private player's.
     */
    private function warnAboutProfileName(PlanRow $row, string $name, string $playerId, string $cell): void
    {
        $player = $this->site->players[$playerId] ?? null;

        if ($player === null || $player['masked'] || $player['name'] === null || trim($player['name']) === '') {
            return;
        }

        if (ParticipantNameKey::of($name) !== ParticipantNameKey::of($player['name'])) {
            $row->messages[] = self::message('linking.name_differs', [
                '%row%' => $row->rowNumber,
                '%name%' => $name,
                '%profile%' => $player['name'],
                '%reference%' => $cell,
            ]);
        }
    }

    /**
     * The "Profile" line of a row (§7.3): shown when the row has a player cell or its person gets linked by the import.
     */
    private function profileOf(int $rowNumber, PlanPerson $person, bool $first): null|ParticipantImportRowProfile
    {
        $playerId = $person->playerId;

        if ($playerId === null || $person->deleted) {
            return null;
        }

        $before = $person->before['playerId'] ?? null;
        $linkedNow = $playerId !== $before;
        $cell = $this->playerReferences[$rowNumber]['cell'] ?? null;

        if ($cell === null && !($linkedNow && $first)) {
            return null;
        }

        if ($linkedNow) {
            $player = $this->site->players[$playerId] ?? null;
            $masked = $player === null || $player['masked'];

            return new ParticipantImportRowProfile(
                linkedNow: true,
                code: $masked ? null : strtoupper($player['code']),
                name: $masked ? null : $player['name'],
                masked: $masked,
                fileReference: $person->linkedByReference,
            );
        }

        $masked = $person->playerMasked || $person->playerCode === null;
        $rowNamesThem = ($this->playerReferences[$rowNumber]['playerId'] ?? null) === $playerId;

        return new ParticipantImportRowProfile(
            linkedNow: false,
            code: $masked ? null : strtoupper((string) $person->playerCode),
            name: $masked ? null : $person->playerName,
            masked: $masked,
            fileReference: $masked && $rowNamesThem ? $cell : null,
            linkedByThemselves: ParticipantLinkMethod::tryFrom((string) $person->connectedVia)?->isThePlayersOwn() === true,
        );
    }

    /**
     * A player is connected to one active participant of the event at most.
     */
    private function canConnect(string $playerId, string $personKey, PlanRow $row, string $name, string $cell): bool
    {
        // A refused pair (D11: declined, "Not this person", "Not me") is never linked again by a file - the organiser
        // links it in the sheet when it is right after all
        if (isset($this->refusedPairs[$personKey][strtolower($playerId)])) {
            $row->messages[] = self::message('linking.link_refused_before', [
                '%row%' => $row->rowNumber,
                '%name%' => $name,
                '%reference%' => $cell,
            ]);

            return false;
        }

        $otherKey = ParticipantRules::playerLinkedTo($playerId, $personKey, $this->links());

        if ($otherKey !== null) {
            $row->messages[] = self::message('player_linked_elsewhere', [
                '%row%' => $row->rowNumber,
                '%name%' => $name,
                '%id%' => $cell,
                '%other%' => $this->people[$otherKey]->name,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @return \Generator<string, array{playerId: null|string, deleted: bool}>
     */
    private function links(): \Generator
    {
        foreach ($this->people as $key => $person) {
            yield $key => ['playerId' => $person->playerId, 'deleted' => $person->deleted];
        }
    }

    private function externalIdTakenByAnother(string $externalId, string $personKey): bool
    {
        // The participants sheet's rule too (external_id_taken) - one rule, ParticipantRules
        return ParticipantRules::externalIdTakenBy($externalId, $personKey, $this->externalIds()) !== null;
    }

    /**
     * @return \Generator<string, array{externalId: null|string}>
     */
    private function externalIds(): \Generator
    {
        foreach ($this->people as $key => $person) {
            yield $key => ['externalId' => $person->externalId];
        }
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
    private function listRowNumbers(array $rowNumbers): string
    {
        $rowNumbers = $this->fileRows($rowNumbers);
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
