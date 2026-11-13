<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sandbox;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\SandboxCatalogueFileInvalid;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Query\IsOrganizationPubliclyVisible;
use SpeedPuzzling\Web\Query\IsSeriesPubliclyVisible;

/**
 * The public catalogue the sandbox starts with (docs/features/sandbox/README.md "Data"): brands, puzzles, their tags and
 * the public events with their rounds and round puzzles - what anybody can read on myspeedpuzzling.com anyway.
 *
 * Never a person: every column that names a player (added_by, approved_by, rejected_by, ...), every moderation note
 * and every picture's upload metadata is left out, and no table of players, results, collections, registrations or
 * messages is listed. A puzzle that is still a secret (hide_until / hide_image_until in the future), an unapproved
 * puzzle and a non-public event are left out too.
 *
 * export() runs read-only on production (myspeedpuzzling:sandbox:export-catalogue) and writes one line per row:
 * `<table>\t<the row as JSON>`. import() (ImportSandboxCatalogueHandler) loads such a file on the sandbox. Both read the
 * same column lists below, so the file can never carry a column this class does not name.
 */
final readonly class SandboxCatalogue
{
    /**
     * Tables in the order they are inserted (foreign keys) => the exported columns. A new column of one of these tables
     * is NOT exported until it is listed here - it gets its default on the sandbox.
     */
    public const array COLUMNS = [
        'tag' => ['id', 'name'],
        'manufacturer' => ['id', 'name', 'approved', 'added_at', 'logo', 'ean_prefix', 'slug'],
        'puzzle' => [
            'id', 'pieces_count', 'name', 'approved', 'image', 'image_ratio', 'added_at', 'is_available', 'approved_at',
            'manufacturer_id', 'alternative_names', 'name_language', 'names_changed_at', 'search_names', 'search_codes',
            'identification_number', 'ean',
        ],
        'tag_puzzle' => ['tag_id', 'puzzle_id'],
        'puzzle_tag' => [
            'puzzle_id', 'tag_key', 'score', 'uncertain', 'source', 'analysis_version', 'taxonomy_version', 'updated_at',
        ],
        'organization' => [
            'id', 'name', 'slug', 'created_at', 'short_name', 'logo', 'about', 'website', 'country_code', 'region', 'kind',
            'social_links', 'is_draft', 'approved_at', 'submitted_at',
        ],
        'competition_series' => [
            'id', 'name', 'slug', 'logo', 'description', 'link', 'is_online', 'location', 'location_country_code',
            'shortcut', 'approved_at', 'created_at', 'submitted_at', 'is_draft', 'eligibility', 'schedule', 'event_types',
            'tag_id', 'organization_id',
        ],
        'competition' => [
            'id', 'name', 'slug', 'shortcut', 'logo', 'description', 'link', 'registration_link', 'results_link',
            'location', 'location_country_code', 'date_from', 'date_to', 'is_online', 'approved_at', 'created_at',
            'submitted_at', 'registration_managed', 'capacity', 'registration_opens_at', 'registration_closes_at',
            'registration_timezone', 'entry_fee_text', 'payment_instructions', 'is_draft', 'eligibility', 'event_types',
            'tag_id', 'series_id', 'organization_id',
        ],
        'competition_round' => [
            'id', 'name', 'minutes_limit', 'starts_at', 'badge_background_color', 'badge_text_color', 'category', 'slug',
            'results_link', 'timezone', 'table_numbers_off', 'reveal_delay_minutes', 'team_size', 'competition_id',
        ],
        'competition_round_puzzle' => [
            'id', 'hide_until_round_starts', 'hide_mode', 'reveal_mode', 'reveal_at', 'hides_everywhere', 'round_id',
            'puzzle_id',
        ],
    ];

    private const string PUBLIC_PUZZLE = <<<SQL
p.approved = true
AND (p.hide_until IS NULL OR p.hide_until <= CAST(:now AS timestamp))
AND (p.hide_image_until IS NULL OR p.hide_image_until <= CAST(:now AS timestamp))
SQL;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return \Generator<int, string> lines `<table>\t<json>` (no newline), tables in COLUMNS order
     */
    public function export(): \Generator
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        foreach (array_keys(self::COLUMNS) as $table) {
            $query = $this->exportQuery($table);
            $sql = sprintf('SELECT row_to_json(x)::text FROM (%s) x', $query);
            $parameters = str_contains($query, ':now') ? ['now' => $now] : [];

            foreach ($this->database->iterateColumn($sql, $parameters) as $json) {
                assert(is_string($json));

                yield $table . "\t" . $json;
            }
        }
    }

    /**
     * Inserts the rows of an export file, in batches, every row at most once (ON CONFLICT DO NOTHING - an import can
     * be run again). Postgres turns the JSON back into the column types (json_populate_recordset), so a jsonb, a
     * timestamp or a boolean arrives as it left.
     *
     * A bulk load of tens of thousands of rows nobody edits one by one - the documented exception to "the write model
     * works with PHP objects" (CLAUDE.md "State-Changing Operations Pattern").
     *
     * @param iterable<string> $lines
     * @return array<string, int> table => rows read from the file
     *
     * @throws SandboxCatalogueFileInvalid
     */
    public function import(iterable $lines): array
    {
        /** @var array<string, list<string>> $batches */
        $batches = [];
        /** @var array<string, int> $counts */
        $counts = array_fill_keys(array_keys(self::COLUMNS), 0);
        $lastTableIndex = -1;
        $tableOrder = array_flip(array_keys(self::COLUMNS));

        $lineNumber = 0;

        foreach ($lines as $line) {
            $lineNumber++;
            $line = rtrim($line, "\r\n");

            if ($line === '') {
                continue;
            }

            $tab = strpos($line, "\t");

            if ($tab === false) {
                throw new SandboxCatalogueFileInvalid(sprintf('Line %d has no table name.', $lineNumber));
            }

            $table = substr($line, 0, $tab);
            $json = substr($line, $tab + 1);

            if (!isset(self::COLUMNS[$table])) {
                throw new SandboxCatalogueFileInvalid(sprintf('Line %d: table "%s" is not part of the catalogue.', $lineNumber, $table));
            }

            $row = json_decode($json, true);

            if (!is_array($row)) {
                throw new SandboxCatalogueFileInvalid(sprintf('Line %d: not a JSON object.', $lineNumber));
            }

            $unknown = array_diff(array_map('strval', array_keys($row)), self::COLUMNS[$table]);

            if ($unknown !== []) {
                throw new SandboxCatalogueFileInvalid(sprintf('Line %d: column(s) %s of "%s" are not exported.', $lineNumber, implode(', ', $unknown), $table));
            }

            // Rows come table by table (export() order) - a later table's rows only after everything they refer to
            if ($tableOrder[$table] < $lastTableIndex) {
                throw new SandboxCatalogueFileInvalid(sprintf('Line %d: "%s" after a later table - the file is not in export order.', $lineNumber, $table));
            }

            if ($tableOrder[$table] > $lastTableIndex) {
                $this->flushAll($batches);
                $lastTableIndex = $tableOrder[$table];
            }

            $batches[$table][] = $json;
            $counts[$table]++;

            if (count($batches[$table]) >= 500) {
                $this->insert($table, $batches[$table]);
                $batches[$table] = [];
            }
        }

        $this->flushAll($batches);

        return $counts;
    }

    private function exportQuery(string $table): string
    {
        $columns = static fn (string $alias): string => implode(', ', array_map(
            static fn (string $column): string => $alias . '.' . $column,
            self::COLUMNS[$table],
        ));
        $publicPuzzle = self::PUBLIC_PUZZLE;
        $publicCompetition = IsCompetitionPubliclyVisible::SQL_CONDITION;
        $publicSeries = IsSeriesPubliclyVisible::SQL_CONDITION;
        $publicOrganization = IsOrganizationPubliclyVisible::SQL_CONDITION;

        return match ($table) {
            'tag' => sprintf('SELECT %s FROM tag t ORDER BY t.id', $columns('t')),
            // Approved brands, and the brand of every exported puzzle even when the brand itself is still unapproved
            'manufacturer' => sprintf(<<<SQL
SELECT %s FROM manufacturer m
WHERE m.approved = true
   OR EXISTS (SELECT 1 FROM puzzle p WHERE p.manufacturer_id = m.id AND {$publicPuzzle})
ORDER BY m.id
SQL, $columns('m')),
            'puzzle' => sprintf('SELECT %s FROM puzzle p WHERE %s ORDER BY p.id', $columns('p'), $publicPuzzle),
            'tag_puzzle' => sprintf(<<<SQL
SELECT %s FROM tag_puzzle tp JOIN puzzle p ON p.id = tp.puzzle_id
WHERE {$publicPuzzle}
ORDER BY tp.tag_id, tp.puzzle_id
SQL, $columns('tp')),
            'puzzle_tag' => sprintf(<<<SQL
SELECT %s FROM puzzle_tag pt JOIN puzzle p ON p.id = pt.puzzle_id
WHERE {$publicPuzzle}
ORDER BY pt.puzzle_id, pt.tag_key
SQL, $columns('pt')),
            'organization' => sprintf('SELECT %s FROM organization o WHERE %s ORDER BY o.id', $columns('o'), $publicOrganization),
            // A series of a non-public organization stays, without the organization
            'competition_series' => sprintf(<<<SQL
SELECT %s FROM (
    SELECT cs.*, CASE WHEN o.id IS NULL THEN NULL ELSE cs.organization_id END AS organization_id_public
    FROM competition_series cs
    LEFT JOIN organization o ON o.id = cs.organization_id AND {$publicOrganization}
    WHERE {$publicSeries}
) s
ORDER BY s.id
SQL, str_replace('s.organization_id', 's.organization_id_public AS organization_id', $columns('s'))),
            'competition' => sprintf(<<<SQL
SELECT %s FROM (
    SELECT c.*, CASE WHEN o.id IS NULL THEN NULL ELSE c.organization_id END AS organization_id_public
    FROM competition c
    LEFT JOIN competition_series cs ON cs.id = c.series_id
    LEFT JOIN organization o ON o.id = c.organization_id AND {$publicOrganization}
    WHERE {$publicCompetition}
) e
ORDER BY e.id
SQL, str_replace('e.organization_id', 'e.organization_id_public AS organization_id', $columns('e'))),
            'competition_round' => sprintf(<<<SQL
SELECT %s FROM competition_round r
JOIN competition c ON c.id = r.competition_id
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE {$publicCompetition}
ORDER BY r.id
SQL, $columns('r')),
            // Only round puzzles whose puzzle is exported - a still-secret round puzzle stays a secret
            'competition_round_puzzle' => sprintf(<<<SQL
SELECT %s FROM competition_round_puzzle rp
JOIN competition_round r ON r.id = rp.round_id
JOIN competition c ON c.id = r.competition_id
LEFT JOIN competition_series cs ON cs.id = c.series_id
JOIN puzzle p ON p.id = rp.puzzle_id
WHERE {$publicCompetition} AND {$publicPuzzle}
ORDER BY rp.id
SQL, $columns('rp')),
            default => throw new \LogicException(sprintf('No export query for "%s".', $table)),
        };
    }

    /**
     * @param array<string, list<string>> $batches
     */
    private function flushAll(array &$batches): void
    {
        foreach ($batches as $table => $rows) {
            if ($rows !== []) {
                $this->insert($table, $rows);
            }
        }

        $batches = [];
    }

    /**
     * @param list<string> $jsonRows
     */
    private function insert(string $table, array $jsonRows): void
    {
        $columns = implode(', ', self::COLUMNS[$table]);

        // $table and $columns come from COLUMNS only (checked above) - never from the file
        $this->database->executeStatement(
            sprintf(
                'INSERT INTO %1$s (%2$s) SELECT %2$s FROM json_populate_recordset(NULL::%1$s, :rows) ON CONFLICT DO NOTHING',
                $table,
                $columns,
            ),
            ['rows' => '[' . implode(',', $jsonRows) . ']'],
        );
    }
}
