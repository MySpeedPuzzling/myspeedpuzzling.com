<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * Which column of an uploaded participant list holds what (docs/features/competitions-management/participant-import-preview.md D4-D6).
 * Lives in the preview's URL: `map[<column index>]=<field value>`, `team_in_round:<round id>` for a round's team column,
 * `division_in_round:<round id>` for a round's division column.
 *
 * Divisions (shared-puzzles-and-divisions.md §3.5): `division` / `divisions` are the Rounds column of an event without
 * a division list - as always (WJPC lists call their rounds divisions) - and the Division column of an event with one;
 * `<round> division` / `division: <round>` is that round's division (a round that uses divisions).
 *
 * Team rows (participant-import-preview.md "As built: team rows"): member columns ("Player 1 name" … "Player 12 name",
 * `MemberName`) make every row of the file one team ticket - toRows() gives one row per filled member cell, all sharing
 * the row's country, rounds, team and division. A Ticket type column says the round: the organiser picks per distinct
 * value which round it means (or "skip") - `tickets[<ticketKey()>]=<round id|skip>` in the URL, guessed until chosen.
 */
readonly final class ColumnMapping
{
    public const string TEAM_IN_ROUND_PREFIX = 'team_in_round:';
    public const string DIVISION_IN_ROUND_PREFIX = 'division_in_round:';

    /** The ticket type's choice "not imported" */
    public const string TICKET_SKIP = 'skip';

    /** At most this many member columns - "Player 1 name" … "Player 12 name" */
    public const int MAX_MEMBERS = 12;

    /**
     * A member of a team row is planned as row `<file row> * MEMBER_ROW_FACTOR + <member>` (the planner needs one row
     * number per person); the preview and every message show the file's row (ParticipantImportRowData::$teamRow).
     */
    public const int MEMBER_ROW_FACTOR = 100;

    /** Headers of the Ticket type column - recognised only next to member columns (a file of people keeps them ignored) */
    private const array TICKET_HEADERS = ['ticket type', 'ticket', 'ticket name', 'ticket type name', 'ticket tier'];

    /**
     * One value per person - a team row has several people, so these are never read from it. An e-mail column of a
     * team ticket is the buyer's: never copied to the members (they get none)
     */
    private const array PER_PERSON_FIELDS = [
        ParticipantImportField::Email,
        ParticipantImportField::PlayerId,
        ParticipantImportField::PlayerCode,
        ParticipantImportField::ParticipantId,
        ParticipantImportField::ExternalId,
        ParticipantImportField::Status,
    ];

    /** Headers of the Division column of an event with a division list - `division(s)` are Rounds without one */
    private const array DIVISION_HEADERS = ['division', 'divisions', 'division name', 'age group', 'age category'];

    /**
     * Headers recognised without the organiser's help - normalised by normaliseHeader().
     *
     * @var array<string, ParticipantImportField>
     */
    private const array HEADERS = [
        'name' => ParticipantImportField::Name,
        'full name' => ParticipantImportField::Name,
        'participant' => ParticipantImportField::Name,
        'participant name' => ParticipantImportField::Name,
        'player' => ParticipantImportField::Name,
        'player name' => ParticipantImportField::Name,
        'first name' => ParticipantImportField::FirstName,
        'firstname' => ParticipantImportField::FirstName,
        'given name' => ParticipantImportField::FirstName,
        'last name' => ParticipantImportField::LastName,
        'lastname' => ParticipantImportField::LastName,
        'surname' => ParticipantImportField::LastName,
        'family name' => ParticipantImportField::LastName,
        'country' => ParticipantImportField::Country,
        'country code' => ParticipantImportField::Country,
        'nation' => ParticipantImportField::Country,
        'round names' => ParticipantImportField::Rounds,
        'rounds' => ParticipantImportField::Rounds,
        'divisions' => ParticipantImportField::Rounds,
        'division' => ParticipantImportField::Rounds,
        'round name' => ParticipantImportField::Round,
        'round' => ParticipantImportField::Round,
        'team name' => ParticipantImportField::Team,
        'team' => ParticipantImportField::Team,
        'msp player id' => ParticipantImportField::PlayerId,
        'msp id' => ParticipantImportField::PlayerId,
        // A player reference - id, #code or profile link (participant-linking.md §7.3); `player` stays the name
        'msp' => ParticipantImportField::PlayerId,
        'msp player' => ParticipantImportField::PlayerId,
        'msp profile' => ParticipantImportField::PlayerId,
        'myspeedpuzzling' => ParticipantImportField::PlayerId,
        'player code' => ParticipantImportField::PlayerId,
        'code' => ParticipantImportField::PlayerId,
        'profile' => ParticipantImportField::PlayerId,
        'msp code' => ParticipantImportField::PlayerCode,
        'participant id' => ParticipantImportField::ParticipantId,
        'external id' => ParticipantImportField::ExternalId,
        'status' => ParticipantImportField::Status,
        // participant-linking.md §7.3 (normaliseHeader() reads "e-mail" as "e mail")
        'email' => ParticipantImportField::Email,
        'e mail' => ParticipantImportField::Email,
        'mail' => ParticipantImportField::Email,
        'email address' => ParticipantImportField::Email,
        'e mail address' => ParticipantImportField::Email,
    ];

    /**
     * @param array<int, ParticipantImportField> $fields column index => field (Ignore left out)
     * @param array<int, string> $teamRounds column index => round id, for the TeamInRound columns
     * @param array<int, string> $divisionRounds column index => round id, for the DivisionInRound columns
     * @param array<string, string> $ticketRounds ticketKey() => round id or TICKET_SKIP, the organiser's choices
     * @param list<ParticipantImportRound> $rounds the event's rounds - a ticket type nobody chose a round for is guessed
     */
    public function __construct(
        public array $fields,
        public array $teamRounds = [],
        public array $divisionRounds = [],
        public array $ticketRounds = [],
        public array $rounds = [],
    ) {
    }

    /**
     * @param list<string> $headers
     * @param list<ParticipantImportRound> $rounds
     * @param bool $hasDivisions the event has a division list (shared-puzzles-and-divisions.md §3.5)
     */
    public static function detect(array $headers, array $rounds, bool $hasDivisions = false): self
    {
        $fields = [];
        $teamRounds = [];
        $divisionRounds = [];

        $members = [];
        $ticketColumn = null;
        $divisionAsRounds = null;

        foreach ($headers as $index => $header) {
            $key = self::normaliseHeader($header);

            if ($key === '') {
                continue;
            }

            $member = self::memberNumberOf($key);

            if ($member !== null) {
                $members[$member] ??= $index;

                continue;
            }

            if ($ticketColumn === null && in_array($key, self::TICKET_HEADERS, true)) {
                $ticketColumn = $index;
            }

            $round = self::teamRoundOf($key, $rounds);

            if ($round !== null) {
                if (!in_array($round->id, $teamRounds, true)) {
                    $fields[$index] = ParticipantImportField::TeamInRound;
                    $teamRounds[$index] = $round->id;
                }

                continue;
            }

            $round = $hasDivisions ? self::divisionRoundOf($key, $rounds) : null;

            if ($round !== null) {
                if (!in_array($round->id, $divisionRounds, true)) {
                    $fields[$index] = ParticipantImportField::DivisionInRound;
                    $divisionRounds[$index] = $round->id;
                }

                continue;
            }

            $field = $hasDivisions && in_array($key, self::DIVISION_HEADERS, true)
                ? ParticipantImportField::Division
                : self::HEADERS[$key] ?? null;

            // The first column of a kind wins; a second one stays ignored until the organiser decides
            if ($field !== null && !in_array($field, $fields, true)) {
                $fields[$index] = $field;

                if ($field === ParticipantImportField::Rounds && in_array($key, ['division', 'divisions'], true)) {
                    $divisionAsRounds = $index;
                }
            }
        }

        // A full name wins over first + last name columns next to it
        if (in_array(ParticipantImportField::Name, $fields, true)) {
            $fields = array_filter(
                $fields,
                static fn (ParticipantImportField $field): bool => $field !== ParticipantImportField::FirstName && $field !== ParticipantImportField::LastName,
            );
        }

        // Member columns: a row is a team ticket - the people are in the member columns, the ticket type says the round
        if ($members !== []) {
            $fields = array_filter(
                $fields,
                static fn (ParticipantImportField $field): bool => !in_array($field, [ParticipantImportField::Name, ParticipantImportField::FirstName, ParticipantImportField::LastName, ...self::PER_PERSON_FIELDS], true),
            );

            foreach ($members as $index) {
                $fields[$index] = ParticipantImportField::MemberName;
            }

            if ($ticketColumn !== null && !isset($fields[$ticketColumn])) {
                $fields[$ticketColumn] = ParticipantImportField::TicketType;

                // The ticket type says the round: a "Division" question of a registration form is no list of rounds
                if ($divisionAsRounds !== null) {
                    unset($fields[$divisionAsRounds]);
                }
            }

            ksort($fields);
        }

        return new self($fields, $teamRounds, $divisionRounds, rounds: $rounds);
    }

    /**
     * @param array<mixed> $map the `map` query parameter
     * @param list<string> $headers
     * @param list<ParticipantImportRound> $rounds
     * @param array<mixed> $tickets the `tickets` query parameter - ticketKey() => round id or TICKET_SKIP
     */
    public static function fromQuery(array $map, array $headers, array $rounds, bool $hasDivisions = false, array $tickets = []): self
    {
        $fields = [];
        $teamRounds = [];
        $divisionRounds = [];
        $teamRoundIds = [];
        $divisionRoundIds = [];
        foreach ($rounds as $round) {
            if ($round->hasTeams()) {
                $teamRoundIds[] = $round->id;
            }

            if ($hasDivisions && $round->usesDivisions()) {
                $divisionRoundIds[] = $round->id;
            }
        }

        foreach ($map as $index => $value) {
            if (!is_int($index) || !array_key_exists($index, $headers) || !is_string($value)) {
                continue;
            }

            if (str_starts_with($value, self::TEAM_IN_ROUND_PREFIX)) {
                $roundId = substr($value, strlen(self::TEAM_IN_ROUND_PREFIX));

                if (in_array($roundId, $teamRoundIds, true)) {
                    $fields[$index] = ParticipantImportField::TeamInRound;
                    $teamRounds[$index] = $roundId;
                }

                continue;
            }

            if (str_starts_with($value, self::DIVISION_IN_ROUND_PREFIX)) {
                $roundId = substr($value, strlen(self::DIVISION_IN_ROUND_PREFIX));

                if (in_array($roundId, $divisionRoundIds, true)) {
                    $fields[$index] = ParticipantImportField::DivisionInRound;
                    $divisionRounds[$index] = $roundId;
                }

                continue;
            }

            $field = ParticipantImportField::tryFrom($value);

            // A Division column only for an event with divisions - an old URL never maps one onto another event
            if ($field === ParticipantImportField::Division && $hasDivisions === false) {
                continue;
            }

            if ($field !== null && $field !== ParticipantImportField::Ignore && $field !== ParticipantImportField::TeamInRound && $field !== ParticipantImportField::DivisionInRound) {
                $fields[$index] = $field;
            }
        }

        ksort($fields);
        ksort($teamRounds);
        ksort($divisionRounds);

        $roundIds = array_map(static fn (ParticipantImportRound $round): string => $round->id, $rounds);
        $ticketRounds = [];
        foreach ($tickets as $key => $choice) {
            if (is_string($key) && preg_match('/^t[0-9a-f]{12}$/', $key) === 1 && is_string($choice) && ($choice === self::TICKET_SKIP || in_array($choice, $roundIds, true))) {
                $ticketRounds[$key] = $choice;
            }
        }
        ksort($ticketRounds);

        return new self($fields, $teamRounds, $divisionRounds, $ticketRounds, $rounds);
    }

    /**
     * Every column, Ignore included - what the preview's selects show and the confirm form sends back.
     *
     * @param list<string> $headers
     * @return array<int, string>
     */
    public function toQuery(array $headers): array
    {
        $query = [];

        foreach (array_keys($headers) as $index) {
            $query[$index] = $this->valueOf($index);
        }

        return $query;
    }

    public function valueOf(int $column): string
    {
        $field = $this->fields[$column] ?? ParticipantImportField::Ignore;

        if ($field === ParticipantImportField::TeamInRound) {
            return self::TEAM_IN_ROUND_PREFIX . ($this->teamRounds[$column] ?? '');
        }

        if ($field === ParticipantImportField::DivisionInRound) {
            return self::DIVISION_IN_ROUND_PREFIX . ($this->divisionRounds[$column] ?? '');
        }

        return $field->value;
    }

    public function column(ParticipantImportField $field): null|int
    {
        $column = array_search($field, $this->fields, true);

        return $column === false ? null : $column;
    }

    public function hasName(): bool
    {
        return $this->memberColumns() !== []
            || $this->column(ParticipantImportField::Name) !== null
            || ($this->column(ParticipantImportField::FirstName) !== null && $this->column(ParticipantImportField::LastName) !== null);
    }

    /**
     * The member columns in column order - non-empty = every row of the file is a team ticket.
     *
     * @return list<int>
     */
    public function memberColumns(): array
    {
        return array_keys(array_filter($this->fields, static fn (ParticipantImportField $field): bool => $field === ParticipantImportField::MemberName));
    }

    /**
     * The distinct ticket types of the sheet in file order, each with the round it is imported into - the organiser's
     * choice, else the guess (guessTicketRound()), null = nobody chose and nothing could be guessed.
     *
     * @return list<array{key: string, value: string, rows: int, choice: null|string, guessed: bool}>
     */
    public function ticketTypes(ParticipantSheet $sheet): array
    {
        $column = $this->column(ParticipantImportField::TicketType);

        if ($column === null) {
            return [];
        }

        $types = [];
        foreach ($sheet->rows as $row) {
            $value = trim((string) preg_replace('/\s+/u', ' ', $row[$column] ?? ''));

            if ($value === '') {
                continue;
            }

            $key = self::ticketKey($value);

            if (isset($types[$key])) {
                $types[$key]['rows']++;

                continue;
            }

            $chosen = $this->ticketRounds[$key] ?? null;
            $types[$key] = [
                'key' => $key,
                'value' => $value,
                'rows' => 1,
                'choice' => $chosen ?? self::guessTicketRound($value, $this->rounds),
                'guessed' => $chosen === null,
            ];
        }

        return array_values($types);
    }

    /**
     * The `tickets` query parameter: the round of every ticket type of the sheet that has one (chosen or guessed), so
     * the confirm applies exactly what the preview showed.
     *
     * @return array<string, string>
     */
    public function ticketQuery(ParticipantSheet $sheet): array
    {
        $query = [];
        foreach ($this->ticketTypes($sheet) as $type) {
            if ($type['choice'] !== null) {
                $query[$type['key']] = $type['choice'];
            }
        }

        return $query;
    }

    /**
     * A ticket type as the `tickets` parameter names it - case and repeated spaces do not matter. Starts with a letter,
     * so PHP never turns the key into an integer.
     */
    public static function ticketKey(string $ticketType): string
    {
        return 't' . substr(hash('sha256', mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $ticketType)))), 0, 12);
    }

    /**
     * The round a ticket type means when the organiser has not said: the round whose name is in it (the longest name
     * wins), else the one round of the kind it names ("team", "pair", "solo"). Null when that is not one round.
     *
     * @param list<ParticipantImportRound> $rounds
     */
    public static function guessTicketRound(string $ticketType, array $rounds): null|string
    {
        $key = self::normaliseHeader($ticketType);
        $best = null;
        $bestLength = 0;

        foreach ($rounds as $round) {
            $roundKey = self::normaliseHeader($round->name);

            if ($roundKey === '' || mb_strlen($roundKey) <= $bestLength) {
                continue;
            }

            if ($key === $roundKey || preg_match('/(^|[^\p{L}\p{N}])' . preg_quote($roundKey, '/') . '($|[^\p{L}\p{N}])/u', $key) === 1) {
                $best = $round->id;
                $bestLength = mb_strlen($roundKey);
            }
        }

        if ($best !== null) {
            return $best;
        }

        $categories = [];
        if (preg_match('/\b(teams?|groups?)\b/u', $key) === 1) {
            $categories[] = RoundCategory::Team;
        }
        if (preg_match('/\b(pairs?|duos?|doubles?|couples?)\b/u', $key) === 1) {
            $categories[] = RoundCategory::Duo;
        }
        if (preg_match('/\b(solos?|individuals?|singles?)\b/u', $key) === 1) {
            $categories[] = RoundCategory::Solo;
        }

        if (count($categories) !== 1) {
            return null;
        }

        $candidates = array_values(array_filter($rounds, static fn (ParticipantImportRound $round): bool => $round->category === $categories[0]));

        return count($candidates) === 1 ? $candidates[0]->id : null;
    }

    /**
     * "Player 1 name", "player 2", "Member 3", "Team member 4", "Name 5", "Participant #6" → the member's number (1-12).
     */
    private static function memberNumberOf(string $normalisedHeader): null|int
    {
        if (preg_match('/^(?:team )?(?:player|member|name|participant|puzzler|competitor) ?#?(\d{1,2})(?: (?:full )?name)?$/u', $normalisedHeader, $matches) !== 1) {
            return null;
        }

        $number = (int) $matches[1];

        return $number >= 1 && $number <= self::MAX_MEMBERS ? $number : null;
    }

    /**
     * @return array<string, int> round id => column index
     */
    public function teamColumnsByRound(): array
    {
        return array_flip($this->teamRounds);
    }

    /**
     * The sheet's rows as the planner reads them. Rows whose mapped cells are all empty are left out
     * (a row with only an address and no name is not a participant); a row with data but no name stays,
     * with name '', so the planner can report it.
     */
    public function toRows(ParticipantSheet $sheet): ParticipantImportRows
    {
        $cell = self::cell(...);

        $nameColumn = $this->column(ParticipantImportField::Name);
        $firstNameColumn = $this->column(ParticipantImportField::FirstName);
        $lastNameColumn = $this->column(ParticipantImportField::LastName);
        $memberColumns = $this->memberColumns();

        $rows = [];
        foreach ($sheet->rows as $rowNumber => $row) {
            if ($nameColumn !== null) {
                $name = (string) $cell($row, $nameColumn);
            } else {
                $name = trim(((string) $cell($row, $firstNameColumn)) . ' ' . ((string) $cell($row, $lastNameColumn)));
            }
            $name = trim((string) preg_replace('/\s+/u', ' ', $name));

            $teamsByRound = [];
            foreach ($this->teamRounds as $column => $roundId) {
                $teamsByRound[$roundId] = (string) $cell($row, $column);
            }

            $divisionsByRound = [];
            foreach ($this->divisionRounds as $column => $roundId) {
                $divisionsByRound[$roundId] = (string) $cell($row, $column);
            }

            $ticketType = $cell($row, $this->column(ParticipantImportField::TicketType));
            $ticketType = $ticketType !== null ? trim((string) preg_replace('/\s+/u', ' ', $ticketType)) : null;
            $ticketRound = $ticketType !== null && $ticketType !== '' ? $this->ticketRoundOf($ticketType) : null;

            if ($memberColumns !== []) {
                foreach ($this->teamRow($row, $rowNumber, $memberColumns, $teamsByRound, $divisionsByRound, $ticketType, $ticketRound) as $data) {
                    $rows[] = $data;
                }

                continue;
            }

            $data = new ParticipantImportRowData(
                rowNumber: $rowNumber,
                name: $name,
                country: $cell($row, $this->column(ParticipantImportField::Country)),
                externalId: $cell($row, $this->column(ParticipantImportField::ExternalId)),
                playerId: $this->playerCell($row),
                participantId: $cell($row, $this->column(ParticipantImportField::ParticipantId)),
                status: $cell($row, $this->column(ParticipantImportField::Status)),
                roundNames: $cell($row, $this->column(ParticipantImportField::Rounds)),
                roundName: $cell($row, $this->column(ParticipantImportField::Round)),
                team: $cell($row, $this->column(ParticipantImportField::Team)),
                teamsByRound: $teamsByRound,
                email: $cell($row, $this->column(ParticipantImportField::Email)),
                division: $cell($row, $this->column(ParticipantImportField::Division)),
                divisionsByRound: $divisionsByRound,
                ticketType: $ticketType,
                ticketRound: $ticketRound,
            );

            $mappedValues = array_filter(
                [$data->name, $data->country, $data->externalId, $data->playerId, $data->participantId, $data->status, $data->roundNames, $data->roundName, $data->team, ...array_values($teamsByRound), $data->division, ...array_values($divisionsByRound), $data->ticketType],
                static fn (null|string $value): bool => $value !== null && $value !== '',
            );

            if ($mappedValues !== []) {
                $rows[] = $data;
            }
        }

        $unmappedHeaders = [];
        foreach ($sheet->headers as $index => $header) {
            if (!isset($this->fields[$index]) && $header !== '') {
                $unmappedHeaders[] = $header;
            }
        }

        return new ParticipantImportRows(
            rows: $rows,
            unmappedHeaders: $unmappedHeaders,
            roundsMapped: $this->column(ParticipantImportField::Rounds) !== null
                || $this->column(ParticipantImportField::Round) !== null
                || $this->column(ParticipantImportField::TicketType) !== null,
        );
    }

    /**
     * One team row of the file: a row per filled member cell (an empty cell is nobody), each with the row's country,
     * rounds, team, division and ticket type. A row with no member but other values stays as one nameless row, so the
     * planner reports it; a row with nothing mapped at all is left out.
     *
     * @param array<int, string> $row
     * @param list<int> $memberColumns
     * @param array<string, string> $teamsByRound
     * @param array<string, string> $divisionsByRound
     * @return list<ParticipantImportRowData>
     */
    private function teamRow(array $row, int $rowNumber, array $memberColumns, array $teamsByRound, array $divisionsByRound, null|string $ticketType, null|string $ticketRound): array
    {
        $cell = self::cell(...);

        $make = fn (int $member, string $name): ParticipantImportRowData => new ParticipantImportRowData(
            rowNumber: $rowNumber * self::MEMBER_ROW_FACTOR + $member,
            name: $name,
            country: $cell($row, $this->column(ParticipantImportField::Country)),
            roundNames: $cell($row, $this->column(ParticipantImportField::Rounds)),
            roundName: $cell($row, $this->column(ParticipantImportField::Round)),
            team: $cell($row, $this->column(ParticipantImportField::Team)),
            teamsByRound: $teamsByRound,
            division: $cell($row, $this->column(ParticipantImportField::Division)),
            divisionsByRound: $divisionsByRound,
            teamRow: $rowNumber,
            member: $member,
            ticketType: $ticketType,
            ticketRound: $ticketRound,
        );

        $people = [];
        foreach ($memberColumns as $position => $column) {
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) $cell($row, $column)));

            if ($name !== '') {
                $people[] = $make($position + 1, $name);
            }
        }

        if ($people !== []) {
            return $people;
        }

        $nameless = $make(0, '');
        $mappedValues = array_filter(
            [$nameless->country, $nameless->roundNames, $nameless->roundName, $nameless->team, ...array_values($teamsByRound), $nameless->division, ...array_values($divisionsByRound), $ticketType],
            static fn (null|string $value): bool => $value !== null && $value !== '',
        );

        return $mappedValues !== [] ? [$nameless] : [];
    }

    /**
     * The round of a ticket type: the organiser's choice, else the guess; null = none.
     */
    private function ticketRoundOf(string $ticketType): null|string
    {
        return $this->ticketRounds[self::ticketKey($ticketType)] ?? self::guessTicketRound($ticketType, $this->rounds);
    }

    /**
     * The row's player reference: the player column, or the `msp_code` column where that is empty (or not mapped)
     *
     * @param array<int, string> $row
     */
    private function playerCell(array $row): null|string
    {
        $player = self::cell($row, $this->column(ParticipantImportField::PlayerId));
        $code = self::cell($row, $this->column(ParticipantImportField::PlayerCode));

        if ($player !== null && $player !== '') {
            return $player;
        }

        return $code ?? $player;
    }

    /**
     * Problems the organiser fixes in the mapping before any preview - translatable, `competition.participants.import.*`.
     *
     * @return list<TranslatableMessage>
     */
    public function errors(): array
    {
        $errors = [];

        if (!$this->hasName()) {
            $errors[] = new TranslatableMessage('competition.participants.import.mapping.name_missing');
        }

        if (
            $this->column(ParticipantImportField::Name) !== null
            && ($this->column(ParticipantImportField::FirstName) !== null || $this->column(ParticipantImportField::LastName) !== null)
        ) {
            $errors[] = new TranslatableMessage('competition.participants.import.mapping.name_twice');
        }

        $members = $this->memberColumns();

        if ($members !== []) {
            if ($this->column(ParticipantImportField::Name) !== null || $this->column(ParticipantImportField::FirstName) !== null || $this->column(ParticipantImportField::LastName) !== null) {
                $errors[] = new TranslatableMessage('team_tickets.mapping.name_with_members');
            }

            foreach (self::PER_PERSON_FIELDS as $field) {
                if ($this->column($field) !== null) {
                    $errors[] = new TranslatableMessage('team_tickets.mapping.per_person_column', [
                        '%field%' => new TranslatableMessage('competition.participants.import.field.' . $field->value),
                    ]);
                }
            }

            if (count($members) > self::MAX_MEMBERS) {
                $errors[] = new TranslatableMessage('team_tickets.mapping.too_many_member_columns', ['%max%' => self::MAX_MEMBERS]);
            }
        }

        $counts = [];
        foreach ($this->fields as $field) {
            if ($field !== ParticipantImportField::TeamInRound && $field !== ParticipantImportField::DivisionInRound && $field !== ParticipantImportField::MemberName) {
                $counts[$field->value] = ($counts[$field->value] ?? 0) + 1;
            }
        }

        foreach ($counts as $field => $count) {
            if ($count > 1) {
                $errors[] = new TranslatableMessage('competition.participants.import.mapping.field_twice', [
                    '%field%' => new TranslatableMessage('competition.participants.import.field.' . $field),
                ]);
            }
        }

        $roundCounts = array_count_values($this->teamRounds);
        foreach ($roundCounts as $count) {
            if ($count > 1) {
                $errors[] = new TranslatableMessage('competition.participants.import.mapping.team_round_twice');
                break;
            }
        }

        foreach (array_count_values($this->divisionRounds) as $count) {
            if ($count > 1) {
                $errors[] = new TranslatableMessage('entry_divisions.import.division_round_twice');
                break;
            }
        }

        return $errors;
    }

    /**
     * "Team_Name: Team Relay" → "team name: team relay" - case, `_`/`-` and repeated spaces do not matter.
     */
    public static function normaliseHeader(string $header): string
    {
        $header = mb_strtolower(trim($header));
        $header = str_replace(['_', '-'], ' ', $header);
        $header = (string) preg_replace('/\s*:\s*/u', ': ', $header);

        return trim((string) preg_replace('/\s+/u', ' ', $header));
    }

    /**
     * "team name: <round>", "team: <round>" or "<round> team" for a pair/team round of the event.
     *
     * @param list<ParticipantImportRound> $rounds
     */
    private static function teamRoundOf(string $normalisedHeader, array $rounds): null|ParticipantImportRound
    {
        foreach ($rounds as $round) {
            if (!$round->hasTeams()) {
                continue;
            }

            $roundKey = self::normaliseHeader($round->name);

            if (in_array($normalisedHeader, ['team name: ' . $roundKey, 'team: ' . $roundKey, $roundKey . ' team'], true)) {
                return $round;
            }
        }

        return null;
    }

    /**
     * "division: <round>", "division name: <round>" or "<round> division" for a round of the event that uses divisions.
     *
     * @param list<ParticipantImportRound> $rounds
     */
    private static function divisionRoundOf(string $normalisedHeader, array $rounds): null|ParticipantImportRound
    {
        foreach ($rounds as $round) {
            if (!$round->usesDivisions()) {
                continue;
            }

            $roundKey = self::normaliseHeader($round->name);

            if (in_array($normalisedHeader, ['division: ' . $roundKey, 'division name: ' . $roundKey, $roundKey . ' division'], true)) {
                return $round;
            }
        }

        return null;
    }

    /**
     * The Division column was found by a `division(s)` header - which an event without divisions reads as its rounds:
     * the preview says how it is read (A14), so a list of rounds called divisions is mapped by hand.
     *
     * @param list<string> $headers
     */
    public function divisionsHeaderReadAsDivision(array $headers): null|string
    {
        $column = $this->column(ParticipantImportField::Division);

        if ($column === null || !in_array(self::normaliseHeader($headers[$column] ?? ''), ['division', 'divisions'], true)) {
            return null;
        }

        return $headers[$column];
    }

    /**
     * @param array<int, string> $row
     */
    private static function cell(array $row, null|int $column): null|string
    {
        return $column === null ? null : trim($row[$column] ?? '');
    }
}
