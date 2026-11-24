<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\ApiBrandListItem;

/**
 * The brands API v1 knows (docs/features/api/README.md "Brands"): every brand, approved or not, with at least one
 * puzzle the catalog search returns - the same visibility as SearchPuzzle (hide_until over), so puzzles_count equals
 * the search's total for the brand, and a brand whose every puzzle is still hidden (a secret competition puzzle, a
 * placeholder) is neither listed nor found by its name: its name must not leak before the reveal.
 */
readonly final class GetApiBrandList
{
    private const string VISIBLE_PUZZLES = <<<SQL
INNER JOIN (
    SELECT puzzle.manufacturer_id, COUNT(*) AS puzzles_count
    FROM puzzle
    WHERE puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp
    GROUP BY puzzle.manufacturer_id
) puzzles ON puzzles.manufacturer_id = manufacturer.id
SQL;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * GET /api/v1/brands - every brand by name, or with $search the brands whose name contains it (accents and case
     * ignored), the closest first: the whole name, a name starting with it, a word starting with it, the rest. One
     * statement.
     *
     * @return list<ApiBrandListItem>
     */
    public function all(null|string $search = null): array
    {
        $parameters = ['now' => $this->clock->now()->format('Y-m-d H:i:s')];
        $condition = '';
        $closeness = '';

        if ($search !== null) {
            // The term as a LIKE pattern of itself: % and _ typed by the client are plain letters
            $parameters['term'] = mb_strtolower(trim($search));
            $escaped = addcslashes($parameters['term'], '%_\\');
            $parameters['pattern'] = '%' . $escaped . '%';
            $parameters['wordPattern'] = $escaped . '%';
            $condition = "WHERE lower(immutable_unaccent(manufacturer.name)) LIKE immutable_unaccent(:pattern)";
            $closeness = <<<SQL
    CASE
        WHEN lower(immutable_unaccent(manufacturer.name)) = immutable_unaccent(:term) THEN 0
        WHEN lower(immutable_unaccent(manufacturer.name)) LIKE immutable_unaccent(:wordPattern) THEN 1
        WHEN ' ' || lower(immutable_unaccent(manufacturer.name)) LIKE '% ' || immutable_unaccent(:wordPattern) THEN 2
        ELSE 3
    END,
SQL;
        }

        $visiblePuzzles = self::VISIBLE_PUZZLES;

        $query = <<<SQL
SELECT
    manufacturer.id,
    manufacturer.name,
    manufacturer.slug,
    manufacturer.logo,
    puzzles.puzzles_count
FROM manufacturer
{$visiblePuzzles}
{$condition}
ORDER BY
{$closeness}
    lower(immutable_unaccent(manufacturer.name)), manufacturer.id
SQL;

        /** @var list<array{id: string, name: string, slug: null|string, logo: null|string, puzzles_count: int}> $rows */
        $rows = $this->database
            ->executeQuery($query, $parameters)
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): ApiBrandListItem => new ApiBrandListItem(
                id: $row['id'],
                name: $row['name'],
                slug: $row['slug'],
                logo: $row['logo'],
                puzzlesCount: $row['puzzles_count'],
            ),
            $rows,
        );
    }

    /**
     * The ids of the listed brands a client means by $text in a brand filter: the whole name with letter case ignored,
     * else the slug. Duplicates happen (a brand typed twice by players): an approved one wins over an unapproved one,
     * so more than one id comes back only when the text cannot decide. Empty when no listed brand is called so.
     *
     * @return list<string>
     */
    public function idsCalled(string $text): array
    {
        $visiblePuzzles = self::VISIBLE_PUZZLES;

        $query = <<<SQL
SELECT manufacturer.id, manufacturer.approved, lower(manufacturer.name) = lower(:text) AS by_name
FROM manufacturer
{$visiblePuzzles}
WHERE lower(manufacturer.name) = lower(:text) OR manufacturer.slug = lower(:text)
ORDER BY by_name DESC, manufacturer.approved DESC, manufacturer.id
SQL;

        /** @var list<array{id: string, approved: bool, by_name: bool}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'text' => trim($text),
            ])
            ->fetchAllAssociative();

        if ($rows === []) {
            return [];
        }

        // Only the best kind of match counts: by name over by slug, approved over unapproved
        $best = $rows[0];
        $tied = array_filter(
            $rows,
            static fn (array $row): bool => $row['by_name'] === $best['by_name'] && $row['approved'] === $best['approved'],
        );

        return array_values(array_map(static fn (array $row): string => $row['id'], $tied));
    }
}
