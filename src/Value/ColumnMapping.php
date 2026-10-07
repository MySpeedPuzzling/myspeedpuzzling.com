<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * Which column of an uploaded participant list holds what (docs/features/competitions-management/participant-import-preview.md D4-D6).
 * Lives in the preview's URL: `map[<column index>]=<field value>`, `team_in_round:<round id>` for a round's team column.
 */
readonly final class ColumnMapping
{
    public const string TEAM_IN_ROUND_PREFIX = 'team_in_round:';

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
        'participant id' => ParticipantImportField::ParticipantId,
        'external id' => ParticipantImportField::ExternalId,
        'status' => ParticipantImportField::Status,
    ];

    /**
     * @param array<int, ParticipantImportField> $fields column index => field (Ignore left out)
     * @param array<int, string> $teamRounds column index => round id, for the TeamInRound columns
     */
    public function __construct(
        public array $fields,
        public array $teamRounds = [],
    ) {
    }

    /**
     * @param list<string> $headers
     * @param list<ParticipantImportRound> $rounds
     */
    public static function detect(array $headers, array $rounds): self
    {
        $fields = [];
        $teamRounds = [];

        foreach ($headers as $index => $header) {
            $key = self::normaliseHeader($header);

            if ($key === '') {
                continue;
            }

            $round = self::teamRoundOf($key, $rounds);

            if ($round !== null) {
                if (!in_array($round->id, $teamRounds, true)) {
                    $fields[$index] = ParticipantImportField::TeamInRound;
                    $teamRounds[$index] = $round->id;
                }

                continue;
            }

            $field = self::HEADERS[$key] ?? null;

            // The first column of a kind wins; a second one stays ignored until the organiser decides
            if ($field !== null && !in_array($field, $fields, true)) {
                $fields[$index] = $field;
            }
        }

        // A full name wins over first + last name columns next to it
        if (in_array(ParticipantImportField::Name, $fields, true)) {
            $fields = array_filter(
                $fields,
                static fn (ParticipantImportField $field): bool => $field !== ParticipantImportField::FirstName && $field !== ParticipantImportField::LastName,
            );
        }

        return new self($fields, $teamRounds);
    }

    /**
     * @param array<mixed> $map the `map` query parameter
     * @param list<string> $headers
     * @param list<ParticipantImportRound> $rounds
     */
    public static function fromQuery(array $map, array $headers, array $rounds): self
    {
        $fields = [];
        $teamRounds = [];
        $teamRoundIds = [];
        foreach ($rounds as $round) {
            if ($round->hasTeams()) {
                $teamRoundIds[] = $round->id;
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

            $field = ParticipantImportField::tryFrom($value);

            if ($field !== null && $field !== ParticipantImportField::Ignore && $field !== ParticipantImportField::TeamInRound) {
                $fields[$index] = $field;
            }
        }

        ksort($fields);
        ksort($teamRounds);

        return new self($fields, $teamRounds);
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

        return $field->value;
    }

    public function column(ParticipantImportField $field): null|int
    {
        $column = array_search($field, $this->fields, true);

        return $column === false ? null : $column;
    }

    public function hasName(): bool
    {
        return $this->column(ParticipantImportField::Name) !== null
            || ($this->column(ParticipantImportField::FirstName) !== null && $this->column(ParticipantImportField::LastName) !== null);
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

            $data = new ParticipantImportRowData(
                rowNumber: $rowNumber,
                name: $name,
                country: $cell($row, $this->column(ParticipantImportField::Country)),
                externalId: $cell($row, $this->column(ParticipantImportField::ExternalId)),
                playerId: $cell($row, $this->column(ParticipantImportField::PlayerId)),
                participantId: $cell($row, $this->column(ParticipantImportField::ParticipantId)),
                status: $cell($row, $this->column(ParticipantImportField::Status)),
                roundNames: $cell($row, $this->column(ParticipantImportField::Rounds)),
                roundName: $cell($row, $this->column(ParticipantImportField::Round)),
                team: $cell($row, $this->column(ParticipantImportField::Team)),
                teamsByRound: $teamsByRound,
            );

            $mappedValues = array_filter(
                [$data->name, $data->country, $data->externalId, $data->playerId, $data->participantId, $data->status, $data->roundNames, $data->roundName, $data->team, ...array_values($teamsByRound)],
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
            roundsMapped: $this->column(ParticipantImportField::Rounds) !== null || $this->column(ParticipantImportField::Round) !== null,
        );
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

        if ($this->column(ParticipantImportField::Name) !== null
            && ($this->column(ParticipantImportField::FirstName) !== null || $this->column(ParticipantImportField::LastName) !== null)) {
            $errors[] = new TranslatableMessage('competition.participants.import.mapping.name_twice');
        }

        $counts = [];
        foreach ($this->fields as $field) {
            if ($field !== ParticipantImportField::TeamInRound) {
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
     * @param array<int, string> $row
     */
    private static function cell(array $row, null|int $column): null|string
    {
        return $column === null ? null : trim($row[$column] ?? '');
    }
}
