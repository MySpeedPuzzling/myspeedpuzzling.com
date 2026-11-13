<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventResultsImport;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Nette\Utils\Json;
use SpeedPuzzling\Web\Entity\PuzzlingTeam;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabProblem;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabProblemCode;
use SpeedPuzzling\Web\Value\EventResultsImport\ParsedEventRow;
use SpeedPuzzling\Web\Value\EventResultsImport\ParsedEventTab;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\SearchText;

/**
 * Reads one tab of Sarah Does Puzzles' results sheet - Google's CSV export of it, in the format agreed with her - into
 * an event and its teams (docs/features/event-results-import/README.md "Format"). Pure: no database, no clock. Row 2 carries the event,
 * every row a team; a wrong event field keeps the whole tab out, a wrong row is skipped.
 */
final class SarahDoesPuzzlesTabParser
{
    private const string NAME = 'name';
    private const string TIME = 'time';
    private const string TEAM_SIZE = 'team size';
    private const string PIECES_PLACED = 'pieces placed';
    private const string CONTEST_NAME = 'contest name';
    private const string LOCATION = 'location';
    private const string CITY_AND_STATE = 'city and state';
    private const string DATE = 'date';
    private const string START_TIME = 'start time';
    private const string TIME_LIMIT = 'time limit';
    private const string COMPETITION_TYPE = 'competition type';
    private const string PUZZLE_LINK = 'link to myspeedpuzzling';

    /** Columns every tab has, as the organizer sees them (for the problem's detail) */
    private const array REQUIRED_COLUMNS = [
        self::NAME => 'Name',
        self::TIME => 'Time',
        self::TEAM_SIZE => 'Team Size',
        self::CONTEST_NAME => 'Contest Name',
        self::LOCATION => 'Location',
        self::CITY_AND_STATE => 'City and State',
        self::DATE => 'Date',
        self::COMPETITION_TYPE => 'Competition Type',
        self::PUZZLE_LINK => 'Link to MySpeedPuzzling',
    ];

    private const int MAX_TEAM_SIZE = 20;

    // The wall clock of an event whose sheet gives only its day (ParsedEventTab::$startTimeKnown = false)
    private const string UNKNOWN_START_TIME = '12:00';

    private const string PUZZLE_LINK_PATTERN = '~^https?://(?:www\.)?myspeedpuzzling\.com/(?:[a-z]{2}/)?puzzle/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?:[/?#].*)?$~i';

    public function parse(string $csv): ParsedEventTab
    {
        $records = $this->records($csv);
        $problems = [];

        $columns = $this->columns($records[0] ?? []);

        foreach (self::REQUIRED_COLUMNS as $key => $label) {
            if (!isset($columns[$key])) {
                $problems[] = new EventTabProblem(EventTabProblemCode::MissingColumn, $label);
            }
        }

        if ($problems !== []) {
            return $this->tab(null, null, null, null, false, null, null, null, [], $problems);
        }

        /** @var list<array{int, array<int, string>}> $dataRecords line + cells, empty rows left out */
        $dataRecords = [];

        foreach (array_slice($records, 1, null, true) as $index => $cells) {
            if (implode('', array_map('trim', $cells)) !== '') {
                $dataRecords[] = [$index + 1, $cells];
            }
        }

        if ($dataRecords === []) {
            return $this->tab(null, null, null, null, false, null, null, null, [], [new EventTabProblem(EventTabProblemCode::NoTeams)]);
        }

        $eventCells = $dataRecords[0][1];

        $eventName = self::clean(self::cell($eventCells, $columns, self::CONTEST_NAME));
        $location = self::clean(self::cell($eventCells, $columns, self::LOCATION));
        $cityAndState = self::clean(self::cell($eventCells, $columns, self::CITY_AND_STATE));

        foreach ([self::CONTEST_NAME => $eventName, self::LOCATION => $location, self::CITY_AND_STATE => $cityAndState] as $column => $value) {
            if ($value === '') {
                $problems[] = new EventTabProblem(EventTabProblemCode::MissingEventField, self::REQUIRED_COLUMNS[$column]);
            }
        }

        $date = $this->required(self::cell($eventCells, $columns, self::DATE), self::DATE, EventTabProblemCode::UnreadableDate, self::date(...), $problems);
        // Optional: without them the event shows its day without a time, and its round no time limit
        $startTime = $this->optional(self::cell($eventCells, $columns, self::START_TIME), EventTabProblemCode::UnreadableStartTime, self::startTime(...), $problems);
        $timeLimit = $this->optional(self::cell($eventCells, $columns, self::TIME_LIMIT), EventTabProblemCode::UnreadableTimeLimit, self::timeLimit(...), $problems);
        $category = $this->required(self::cell($eventCells, $columns, self::COMPETITION_TYPE), self::COMPETITION_TYPE, EventTabProblemCode::UnknownCompetitionType, self::category(...), $problems);

        $puzzleId = null;
        $link = self::cell($eventCells, $columns, self::PUZZLE_LINK);

        if ($link !== '') {
            if (preg_match(self::PUZZLE_LINK_PATTERN, $link, $match) === 1) {
                $puzzleId = strtolower($match[1]);
            } else {
                $problems[] = new EventTabProblem(EventTabProblemCode::UnreadablePuzzleLink, $link);
            }
        }

        $localStartsAt = null;

        if ($date !== null) {
            // Only the day known: noon of it - the round is dated by its start, and noon keeps it on that day in every zone
            $localStartsAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . ($startTime ?? self::UNKNOWN_START_TIME), new DateTimeZone('UTC'));
            $localStartsAt = $localStartsAt === false ? null : $localStartsAt;
        }

        $piecesColumn = $columns[self::PIECES_PLACED] ?? null;

        /** @var array<string, list<ParsedEventRow>> $rowsByKey */
        $rowsByKey = [];

        foreach ($dataRecords as [$line, $cells]) {
            $row = $this->row(
                line: $line,
                name: self::clean(self::cell($cells, $columns, self::NAME)),
                size: self::cell($cells, $columns, self::TEAM_SIZE),
                time: self::cell($cells, $columns, self::TIME),
                piecesPlaced: $piecesColumn === null ? '' : trim($cells[$piecesColumn] ?? ''),
                category: $category,
                problems: $problems,
            );

            if ($row !== null) {
                $rowsByKey[$row->teamKey][] = $row;
            }
        }

        $rows = [];

        foreach ($rowsByKey as $sameTeam) {
            if (count($sameTeam) > 1) {
                // Which of them is the team's result nobody can tell - none is imported until the organizer fixes it
                $problems[] = new EventTabProblem(EventTabProblemCode::TeamNamedTwice, $sameTeam[0]->teamName, $sameTeam[1]->line);
                continue;
            }

            $rows[] = $sameTeam[0];
        }

        usort($rows, static fn (ParsedEventRow $a, ParsedEventRow $b): int => $a->line <=> $b->line);

        // A tab whose every row went wrong must never read as "every team is gone" - the import would remove them
        if ($rows === []) {
            $problems[] = new EventTabProblem(EventTabProblemCode::NoTeams);
        }

        return $this->tab($eventName, $location, $cityAndState, $localStartsAt, $startTime !== null, $timeLimit, $category, $puzzleId, $rows, $problems);
    }

    /**
     * @param list<EventTabProblem> $problems
     */
    private function row(
        int $line,
        string $name,
        string $size,
        string $time,
        string $piecesPlaced,
        null|RoundCategory $category,
        array &$problems,
    ): null|ParsedEventRow {
        if ($name === '') {
            $problems[] = new EventTabProblem(EventTabProblemCode::RowWithoutTeamName, null, $line);

            return null;
        }

        if (mb_strlen($name) > PuzzlingTeam::NAME_MAX_LENGTH) {
            $problems[] = new EventTabProblem(EventTabProblemCode::TeamNameTooLong, $name, $line);

            return null;
        }

        $teamSize = preg_match('/^\d{1,2}$/', $size) === 1 ? (int) $size : 0;

        if ($teamSize < 1 || $teamSize > self::MAX_TEAM_SIZE) {
            $problems[] = new EventTabProblem(EventTabProblemCode::UnreadableTeamSize, $name, $line);

            return null;
        }

        // The round's category follows from the people - a pair at a teams event would not be the round's result
        $fits = match ($category) {
            RoundCategory::Duo => $teamSize === 2,
            RoundCategory::Team => $teamSize >= 3,
            RoundCategory::Solo, null => true,
        };

        if ($fits === false) {
            $problems[] = new EventTabProblem(EventTabProblemCode::TeamSizeDoesNotFitCategory, $name, $line);

            return null;
        }

        if ($time === '' && $piecesPlaced === '') {
            $problems[] = new EventTabProblem(EventTabProblemCode::RowWithoutResult, $name, $line);

            return null;
        }

        if ($time !== '' && $piecesPlaced !== '') {
            $problems[] = new EventTabProblem(EventTabProblemCode::TimeAndPiecesPlaced, $name, $line);

            return null;
        }

        if ($time === '') {
            $problems[] = new EventTabProblem(EventTabProblemCode::UnfinishedNotSupportedYet, $name, $line);

            return null;
        }

        $seconds = self::seconds($time);

        if ($seconds === null) {
            $problems[] = new EventTabProblem(EventTabProblemCode::UnreadableTime, $name . ': ' . $time, $line);

            return null;
        }

        return new ParsedEventRow($line, $name, SearchText::fold($name), $teamSize, $seconds);
    }

    /**
     * @template T
     * @param callable(string): (null|T) $read
     * @param list<EventTabProblem> $problems
     * @return null|T
     */
    private function required(string $value, string $column, EventTabProblemCode $unreadable, callable $read, array &$problems): mixed
    {
        if ($value === '') {
            $problems[] = new EventTabProblem(EventTabProblemCode::MissingEventField, self::REQUIRED_COLUMNS[$column]);

            return null;
        }

        $read = $read($value);

        if ($read === null) {
            $problems[] = new EventTabProblem($unreadable, $value);
        }

        return $read;
    }

    /**
     * An empty cell (or no such column) is fine; a value that cannot be read is a problem - a typo must not quietly
     * drop the time the organizer meant to give.
     *
     * @template T
     * @param callable(string): (null|T) $read
     * @param list<EventTabProblem> $problems
     * @return null|T
     */
    private function optional(string $value, EventTabProblemCode $unreadable, callable $read, array &$problems): mixed
    {
        if ($value === '') {
            return null;
        }

        $read = $read($value);

        if ($read === null) {
            $problems[] = new EventTabProblem($unreadable, $value);
        }

        return $read;
    }

    /**
     * @param list<ParsedEventRow> $rows
     * @param list<EventTabProblem> $problems
     */
    private function tab(
        null|string $eventName,
        null|string $location,
        null|string $cityAndState,
        null|DateTimeImmutable $localStartsAt,
        bool $startTimeKnown,
        null|int $timeLimitMinutes,
        null|RoundCategory $category,
        null|string $puzzleId,
        array $rows,
        array $problems,
    ): ParsedEventTab {
        $eventName = $eventName === '' ? null : $eventName;
        $location = $location === '' ? null : $location;
        $cityAndState = $cityAndState === '' ? null : $cityAndState;

        $contentHash = sha1(Json::encode([
            $eventName,
            $location,
            $cityAndState,
            $localStartsAt?->format('Y-m-d H:i'),
            $startTimeKnown,
            $timeLimitMinutes,
            $category?->value,
            $puzzleId,
            array_map(static fn (ParsedEventRow $row): array => [$row->line, $row->teamName, $row->teamSize, $row->seconds], $rows),
            array_map(static fn (EventTabProblem $problem): array => $problem->toArray(), $problems),
        ]));

        return new ParsedEventTab($eventName, $location, $cityAndState, $localStartsAt, $startTimeKnown, $timeLimitMinutes, $category, $puzzleId, $rows, $problems, $contentHash);
    }

    /**
     * @return list<array<int, string>>
     */
    private function records(string $csv): array
    {
        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            throw new LogicException('Could not open a memory stream.');
        }

        fwrite($handle, $csv);
        rewind($handle);

        $records = [];

        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $records[] = array_map(static fn (null|string $cell): string => $cell ?? '', $cells);
        }

        fclose($handle);

        return $records;
    }

    /**
     * @param array<int, string> $headerCells
     * @return array<string, int> normalised header => column index (the first of two equal headers wins)
     */
    private function columns(array $headerCells): array
    {
        $columns = [];

        foreach ($headerCells as $index => $header) {
            $key = mb_strtolower(self::clean($header));

            if ($key !== '' && !isset($columns[$key])) {
                $columns[$key] = $index;
            }
        }

        return $columns;
    }

    /**
     * @param array<int, string> $cells
     * @param array<string, int> $columns
     */
    private static function cell(array $cells, array $columns, string $column): string
    {
        // An optional column may not be there at all
        if (isset($columns[$column]) === false) {
            return '';
        }

        return trim($cells[$columns[$column]] ?? '');
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * "1:10:13", "0:24:48" or "24:48" (minutes and seconds).
     */
    private static function seconds(string $time): null|int
    {
        if (preg_match('/^(\d{1,2}):([0-5]\d):([0-5]\d)$/', $time, $match) === 1) {
            $seconds = (int) $match[1] * 3600 + (int) $match[2] * 60 + (int) $match[3];
        } elseif (preg_match('/^(\d{1,3}):([0-5]\d)$/', $time, $match) === 1) {
            $seconds = (int) $match[1] * 60 + (int) $match[2];
        } else {
            return null;
        }

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * The sheet is American: "10/5/26" or "10/05/2026" is month/day/year; "2026-10-05" is read as ISO.
     */
    private static function date(string $date): null|string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $match) === 1) {
            [$year, $month, $day] = [(int) $match[1], (int) $match[2], (int) $match[3]];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2}|\d{4})$#', $date, $match) === 1) {
            [$month, $day, $year] = [(int) $match[1], (int) $match[2], (int) $match[3]];
            $year = $year < 100 ? 2000 + $year : $year;
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /**
     * "7:00 PM", "7 pm", "12:30 PM" or the 24-hour "18:30".
     */
    private static function startTime(string $time): null|string
    {
        if (preg_match('/^(\d{1,2})(?::([0-5]\d))?\s*([ap])\.?\s*m\.?$/i', $time, $match) === 1) {
            $hour = (int) $match[1];

            if ($hour < 1 || $hour > 12) {
                return null;
            }

            $hour = $hour % 12 + (strtolower($match[3]) === 'p' ? 12 : 0);

            // An hour without minutes leaves the minutes group empty
            return sprintf('%02d:%02d', $hour, (int) $match[2]);
        }

        if (preg_match('/^(\d{1,2}):([0-5]\d)$/', $time, $match) === 1 && (int) $match[1] <= 23) {
            return sprintf('%02d:%02d', (int) $match[1], (int) $match[2]);
        }

        return null;
    }

    /**
     * Minutes: "120" or "120 min".
     */
    private static function timeLimit(string $limit): null|int
    {
        if (preg_match('/^(\d{1,4})\s*(?:min|mins|minutes)?$/i', $limit, $match) !== 1) {
            return null;
        }

        $minutes = (int) $match[1];

        return $minutes >= 1 && $minutes <= 1440 ? $minutes : null;
    }

    private static function category(string $type): null|RoundCategory
    {
        return match (mb_strtolower($type)) {
            'teams', 'team' => RoundCategory::Team,
            'pairs', 'pair', 'duo', 'duos' => RoundCategory::Duo,
            default => null,
        };
    }
}
