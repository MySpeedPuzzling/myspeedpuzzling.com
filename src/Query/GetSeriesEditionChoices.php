<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\SeriesEditionChoice;
use SpeedPuzzling\Web\Value\SearchText;

/**
 * The editions a player picks explicitly on the add-time form (docs/features/events-page/high-frequency-series.md
 * "The form") - never baked into the page, always asked for:
 *
 * - search() (S1): typing two or more characters in the picker finds editions by name - every typed word in the
 *   edition's name, its series' name or shortcut;
 * - closest() (the short list under a series' preview, S2): one series' editions, closest to the solve day first;
 * - seriesOfSelectableEdition(): whether an `edition:<uuid>` value may be picked, and its series.
 *
 * Only publicly visible editions of publicly visible series, never a draft, pending or rejected one - the candidates of
 * the matching rule (SeriesEditionMatch::sqlCandidates(), which also gives the day span and the REVEALED round
 * puzzles). A round puzzle a round still keeps secret is neither listed nor found by the short list's search.
 *
 * @phpstan-import-type SeriesEditionChoiceDatabaseRow from SeriesEditionChoice
 */
readonly final class GetSeriesEditionChoices
{
    public const int SEARCH_LIMIT = 20;

    public const int SHORT_LIST_LIMIT = 10;

    public const int MIN_SEARCH_LENGTH = 2;

    private const int MAX_SEARCH_WORDS = 6;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * S1: editions whose name, series name or series shortcut contains every typed word (accents and case ignored),
     * nearest to today first, undated last. Nothing for fewer than two characters.
     *
     * @return list<SeriesEditionChoice>
     */
    public function search(string $query, int $limit = self::SEARCH_LIMIT): array
    {
        $words = array_slice(array_values(array_filter(
            preg_split('/\s+/u', trim($query)) ?: [],
            static fn (string $word): bool => $word !== '',
        )), 0, self::MAX_SEARCH_WORDS);

        if (mb_strlen(trim($query)) < self::MIN_SEARCH_LENGTH || $words === []) {
            return [];
        }

        $parameters = [];
        $conditions = [];

        foreach ($words as $index => $word) {
            $conditions[] = "immutable_unaccent(c.name || ' ' || cs.name || ' ' || COALESCE(cs.shortcut, '')) ILIKE immutable_unaccent(:word{$index})";
            $parameters['word' . $index] = '%' . addcslashes($word, '\\%_') . '%';
        }

        // The words narrow the candidates right where they are read (the CTE's own competition c and series cs)
        $scope = 'c.series_id IS NOT NULL AND ' . implode(' AND ', $conditions);
        $candidates = SeriesEditionMatch::sqlCandidates($scope);
        $distance = self::sqlDistance('CAST(:today AS DATE)');

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
WITH {$candidates}
SELECT c.id,
    c.name,
    cs.id AS series_id,
    cs.name AS series_name,
    cs.shortcut AS series_shortcut,
    COALESCE(c.logo, cs.logo) AS logo,
    cs.logo AS series_logo,
    COALESCE(c.location, cs.location) AS location,
    COALESCE(c.location_country_code, cs.location_country_code) AS location_country_code,
    e.span_from,
    e.span_to,
    NULL AS categories,
    NULL AS puzzle_names
FROM series_match_edition e
INNER JOIN competition c ON c.id = e.competition_id
INNER JOIN competition_series cs ON cs.id = c.series_id
ORDER BY e.span_from IS NULL, {$distance}, e.span_from DESC, c.id DESC
LIMIT :limit
SQL,
            [
                ...$parameters,
                'today' => $this->clock->now()->format('Y-m-d'),
                'limit' => $limit,
                SeriesEditionMatch::NOW_PARAMETER => $this->clock->now()->format(SeriesEditionMatch::DATE_FORMAT),
            ],
        );

        return array_map(static function (array $row): SeriesEditionChoice {
            /** @var SeriesEditionChoiceDatabaseRow $row */
            return SeriesEditionChoice::fromDatabaseRow($row);
        }, $rows);
    }

    /**
     * The short list: the series' editions, closest to the solve day first (dated ones by their distance in days to
     * their span, then undated ones newest first), each with its categories and revealed round puzzle names.
     *
     * $query narrows it to editions whose name or revealed round puzzle names hold every typed word (folded like every
     * other event search, SearchText::fold()). $alwaysIncludeId (the matched or the picked edition) is listed even
     * beyond $limit - in its own place, so the line above the list can name it without another statement.
     *
     * @return list<SeriesEditionChoice>
     */
    public function closest(
        string $seriesId,
        DateTimeImmutable $day,
        null|string $query = null,
        int $limit = self::SHORT_LIST_LIMIT,
        null|string $alwaysIncludeId = null,
    ): array {
        if (Uuid::isValid($seriesId) === false) {
            return [];
        }

        $words = $query !== null ? array_values(array_filter(explode(' ', SearchText::fold($query)), static fn (string $word): bool => $word !== '')) : [];
        $candidates = SeriesEditionMatch::sqlCandidates('c.series_id = CAST(:seriesId AS UUID)');
        $distance = self::sqlDistance('CAST(:day AS DATE)');

        // A search is applied below, in PHP, with the one fold of puzzle text - the whole series is read for it
        $cut = $words === [] ? 'WHERE x.position <= :limit OR x.id = CAST(:includeId AS UUID)' : '';

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
WITH {$candidates}
SELECT x.id, x.name, x.series_id, x.series_name, x.series_shortcut, x.logo, x.series_logo, x.location,
    x.location_country_code, x.span_from, x.span_to, x.categories, x.puzzle_names
FROM (
    SELECT c.id,
        c.name,
        cs.id AS series_id,
        cs.name AS series_name,
        cs.shortcut AS series_shortcut,
        COALESCE(c.logo, cs.logo) AS logo,
        cs.logo AS series_logo,
        COALESCE(c.location, cs.location) AS location,
        COALESCE(c.location_country_code, cs.location_country_code) AS location_country_code,
        e.span_from,
        e.span_to,
        array_to_json(e.categories) AS categories,
        names.puzzle_names,
        ROW_NUMBER() OVER (ORDER BY e.span_from IS NULL, {$distance}, e.span_from DESC, c.created_at DESC NULLS LAST, c.id DESC) AS position
    FROM series_match_edition e
    INNER JOIN competition c ON c.id = e.competition_id
    INNER JOIN competition_series cs ON cs.id = c.series_id
    LEFT JOIN LATERAL (
        -- Revealed round puzzles only (the rule's own CTE)
        SELECT json_agg(DISTINCT p.name ORDER BY p.name) AS puzzle_names
        FROM series_match_round_puzzle rp
        INNER JOIN puzzle p ON p.id = rp.puzzle_id
        WHERE rp.competition_id = e.competition_id
    ) names ON true
) x
{$cut}
ORDER BY x.position
SQL,
            [
                'seriesId' => $seriesId,
                'day' => $day->format('Y-m-d'),
                'limit' => $limit,
                'includeId' => $alwaysIncludeId !== null && Uuid::isValid($alwaysIncludeId) ? $alwaysIncludeId : null,
                SeriesEditionMatch::NOW_PARAMETER => $this->clock->now()->format(SeriesEditionMatch::DATE_FORMAT),
            ],
        );

        $choices = array_map(static function (array $row): SeriesEditionChoice {
            /** @var SeriesEditionChoiceDatabaseRow $row */
            return SeriesEditionChoice::fromDatabaseRow($row);
        }, $rows);

        if ($words === []) {
            return $choices;
        }

        $found = array_values(array_filter($choices, static function (SeriesEditionChoice $choice) use ($words): bool {
            $haystack = SearchText::fold($choice->name . ' ' . implode(' ', $choice->puzzleNames));

            foreach ($words as $word) {
                if (str_contains($haystack, $word) === false) {
                    return false;
                }
            }

            return true;
        }));

        return array_slice($found, 0, $limit);
    }

    /**
     * The series of a publicly visible edition - null for anything else (a one-time event, a draft, pending or rejected
     * edition, an edition of a series that is not public). One statement.
     */
    public function seriesOfSelectableEdition(string $editionId): null|string
    {
        if (Uuid::isValid($editionId) === false) {
            return null;
        }

        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;

        $seriesId = $this->database->fetchOne(
            <<<SQL
SELECT c.series_id
FROM competition c
INNER JOIN competition_series cs ON cs.id = c.series_id
WHERE c.id = :editionId
    AND {$visible}
SQL,
            ['editionId' => $editionId],
        );

        return is_string($seriesId) ? $seriesId : null;
    }

    public function isSelectableEdition(string $editionId): bool
    {
        return $this->seriesOfSelectableEdition($editionId) !== null;
    }

    /**
     * Days between a day and an edition's span (0 inside it) - the order of the lists only; NULL without a span
     */
    private static function sqlDistance(string $day): string
    {
        return "CASE WHEN {$day} BETWEEN e.span_from AND e.span_to THEN 0"
            . " ELSE LEAST(ABS({$day} - e.span_from), ABS({$day} - e.span_to)) END";
    }
}
