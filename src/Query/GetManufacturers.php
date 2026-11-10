<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\ManufacturerOverview;
use SpeedPuzzling\Web\Value\PuzzleSecrecy;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

readonly final class GetManufacturers
{
    /**
     * How long the every-brand list is kept for one fingerprint: only the "(123)" puzzle counts of public puzzles and
     * the order by them can be this much behind - which brands are listed never is (overviewsFingerprint()).
     */
    private const int CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private CacheInterface $manufacturerOverviewsCache,
    ) {
    }

    /**
     * Every brand, approved or not, whoever added it - for the pickers where a player chooses the
     * brand of a puzzle. A brand left out there gets typed again and becomes a duplicate
     * (docs/features/brand-duplicates.md).
     *
     * Secret competition puzzles (PuzzleSecrecy) are not counted, and a brand whose every puzzle is secret (typed for
     * a secret puzzle) is left out - except, in the add-to-round form, the brands of $secretPuzzlesOfCompetitionId's
     * round puzzles for its organisers, and the brands of the organiser's own secret puzzles that are in no round any more
     * (the round picker offers those puzzles too).
     *
     * The list without parameters - the same for everybody, built on every add/edit-time form - is cached under
     * overviewsFingerprint(); the organisers' variants (with a competition or a player) are never cached, so their
     * secret brands can never reach the shared entry.
     *
     * @return array<ManufacturerOverview>
     */
    public function allIncludingUnapproved(
        null|string $secretPuzzlesOfCompetitionId = null,
        null|string $secretPuzzlesAddedByPlayerId = null,
    ): array {
        if ($secretPuzzlesOfCompetitionId !== null || $secretPuzzlesAddedByPlayerId !== null) {
            return $this->overviews('true', [], $secretPuzzlesOfCompetitionId, $secretPuzzlesAddedByPlayerId);
        }

        $key = 'manufacturer_overviews_v1_' . $this->overviewsFingerprint();

        /** @var array<ManufacturerOverview> $overviews */
        $overviews = $this->manufacturerOverviewsCache->get($key, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            return $this->overviews('true', []);
        });

        return $overviews;
    }

    /**
     * A hash of everything that decides which brands the every-brand list holds and how each is shown (~3 ms on
     * production's 2,300 brands, a list costs ~20 ms + hydration):
     * - every brand row as text - a new brand (typed into the add form: its creator sees it on the next form), a
     *   rename, logo, EAN prefix, approval, merge or delete;
     * - every hidden puzzle row as text, and how many are hidden at :now - a new secret puzzle, a reveal by hand and
     *   the reveal moment itself (a brand whose every puzzle is secret appears exactly then);
     * - how many puzzles each brand holding a hidden puzzle has - a brand left with nothing but secret puzzles
     *   disappears at once;
     * - the round puzzles hiding their puzzle everywhere (what makes a hidden approved puzzle secret).
     * Not in it: the counts of public puzzles of other brands - those may lag CACHE_TTL_SECONDS.
     * Content hashes, not xmin: a second change of a row in one transaction keeps its xmin.
     */
    private function overviewsFingerprint(): string
    {
        /** @var array<string, string> $row One row, always: a SELECT of scalar subqueries */
        $row = $this->database->executeQuery(<<<'SQL'
SELECT
    (SELECT COUNT(*) || ':' || COALESCE(SUM(hashtext(manufacturer::text)::bigint), 0) FROM manufacturer) AS brands,
    (
        SELECT COUNT(*) || ':' || COALESCE(SUM(hashtext(hidden::text)::bigint), 0)
            || ':' || COUNT(*) FILTER (WHERE hidden.hide_until > :now::timestamp)
            || ':' || COUNT(*) FILTER (WHERE hidden.hide_image_until > :now::timestamp)
        FROM puzzle hidden
        WHERE hidden.hide_until IS NOT NULL OR hidden.hide_image_until IS NOT NULL
    ) AS hidden_puzzles,
    (
        SELECT COUNT(*) || ':' || COALESCE(SUM(hashtext(brand_total.manufacturer_id::text || ':' || brand_total.total)::bigint), 0)
        FROM (
            SELECT of_brand.manufacturer_id, COUNT(*) AS total
            FROM puzzle of_brand
            WHERE of_brand.manufacturer_id IN (
                SELECT hidden_brand.manufacturer_id
                FROM puzzle hidden_brand
                WHERE hidden_brand.hide_until IS NOT NULL OR hidden_brand.hide_image_until IS NOT NULL
            )
            GROUP BY of_brand.manufacturer_id
        ) brand_total
    ) AS brands_with_hidden_puzzles,
    (
        SELECT COUNT(*) || ':' || COALESCE(SUM(hashtext(crp::text)::bigint), 0)
        FROM competition_round_puzzle crp
        WHERE crp.hides_everywhere
    ) AS secret_round_puzzles
SQL, ['now' => $this->clock->now()->format('Y-m-d H:i:s')])->fetchAssociative();

        return sha1(implode('|', $row));
    }

    /**
     * Approved brands, plus the player's own and one extra brand when given - for public lists
     * (search filters) and the admin pages that suggest approved brands only.
     *
     * @return array<ManufacturerOverview>
     */
    public function onlyApprovedOrAddedByPlayer(null|string $playerId = null, null|string $extraManufacturerId = null): array
    {
        return $this->overviews(
            '(manufacturer.approved = true OR manufacturer.added_by_user_id = :playerId OR manufacturer.id = :extraManufacturerId)',
            [
                'playerId' => $playerId,
                'extraManufacturerId' => $extraManufacturerId,
            ],
        );
    }

    /**
     * @param array<string, null|string> $parameters
     *
     * @return array<ManufacturerOverview>
     */
    private function overviews(
        string $condition,
        array $parameters,
        null|string $secretPuzzlesOfCompetitionId = null,
        null|string $secretPuzzlesAddedByPlayerId = null,
    ): array {
        $notSecret = PuzzleSecrecy::sqlNotSecret('puzzle');
        $parameters['now'] = $this->clock->now()->format('Y-m-d H:i:s');
        $secretOfCompetition = '';

        if ($secretPuzzlesOfCompetitionId !== null) {
            $secretOfCompetition = 'OR manufacturer.id IN (
        SELECT competition_puzzle.manufacturer_id
        FROM competition_round_puzzle crp
        INNER JOIN competition_round cr ON cr.id = crp.round_id
        INNER JOIN puzzle competition_puzzle ON competition_puzzle.id = crp.puzzle_id
        WHERE cr.competition_id = :secretCompetitionId
    )';
            $parameters['secretCompetitionId'] = $secretPuzzlesOfCompetitionId;
        }

        if ($secretPuzzlesAddedByPlayerId !== null) {
            $secretOfCompetition .= ' OR manufacturer.id IN (
        SELECT orphan_puzzle.manufacturer_id
        FROM puzzle orphan_puzzle
        WHERE orphan_puzzle.added_by_user_id = :secretAddedBy
            AND NOT EXISTS (SELECT 1 FROM competition_round_puzzle orphan_crp WHERE orphan_crp.puzzle_id = orphan_puzzle.id)
    )';
            $parameters['secretAddedBy'] = $secretPuzzlesAddedByPlayerId;
        }

        $query = <<<SQL
SELECT
    manufacturer.id AS manufacturer_id,
    manufacturer.name AS manufacturer_name,
    manufacturer.approved AS manufacturer_approved,
    manufacturer.logo AS manufacturer_logo,
    manufacturer.ean_prefix AS manufacturer_ean_prefix,
    COUNT(puzzle.id) FILTER (WHERE {$notSecret}) AS puzzles_count
FROM manufacturer
LEFT JOIN puzzle ON puzzle.manufacturer_id = manufacturer.id
WHERE {$condition}
GROUP BY manufacturer.id
HAVING COUNT(puzzle.id) = 0
    OR COUNT(puzzle.id) FILTER (WHERE {$notSecret}) > 0
    {$secretOfCompetition}
ORDER BY COUNT(puzzle.id) FILTER (WHERE {$notSecret}) DESC, manufacturer.name ASC
SQL;

        $data = $this->database
            ->executeQuery($query, $parameters)
            ->fetchAllAssociative();

        return array_map(static function (array $row): ManufacturerOverview {
            /**
             * @var array{
             *      manufacturer_id: string,
             *      manufacturer_name: string,
             *      manufacturer_approved: bool,
             *      manufacturer_logo: string|null,
             *      manufacturer_ean_prefix: string|null,
             *      puzzles_count: int,
             * } $row
             */
            return ManufacturerOverview::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * Plain names for a handful of manufacturer ids (filter chips, pre-selected
     * options). Unknown ids are simply absent from the result.
     *
     * @param list<string> $manufacturerIds
     *
     * @return array<string, string> manufacturerId => name
     */
    public function namesByIds(array $manufacturerIds): array
    {
        return array_map(static fn (array $row): string => $row['name'], $this->namesAndLogosByIds($manufacturerIds));
    }

    /**
     * Names + raw logo paths for a handful of manufacturer ids (pre-selected
     * Tom Select options render the logo like the option list does).
     *
     * @param list<string> $manufacturerIds
     *
     * @return array<string, array{name: string, logo: null|string}> manufacturerId => name/logo
     */
    public function namesAndLogosByIds(array $manufacturerIds): array
    {
        if ($manufacturerIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT manufacturer.id AS manufacturer_id, manufacturer.name AS manufacturer_name, manufacturer.logo AS manufacturer_logo
FROM manufacturer
WHERE manufacturer.id IN (:manufacturerIds)
SQL;

        /** @var array<array{manufacturer_id: string, manufacturer_name: string, manufacturer_logo: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($query, ['manufacturerIds' => $manufacturerIds], ['manufacturerIds' => ArrayParameterType::STRING])
            ->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            $result[$row['manufacturer_id']] = ['name' => $row['manufacturer_name'], 'logo' => $row['manufacturer_logo']];
        }

        return $result;
    }

    /**
     * Find all manufacturers by EAN prefix.
     * Tries first 7 digits, then 6 digits of the EAN (after stripping leading zeros).
     *
     * @return array<array{manufacturer_id: string, manufacturer_name: string, manufacturer_ean_prefix: string|null}>
     */
    public function allByEanPrefix(string $ean): array
    {
        // Strip leading zeros
        $eanStripped = ltrim($ean, '0');

        if (strlen($eanStripped) < 6) {
            return [];
        }

        // Try with first 7 digits
        $prefix7 = substr($eanStripped, 0, 7);
        $results = $this->findAllManufacturersByPrefix($prefix7);

        if ($results !== []) {
            return $results;
        }

        // Try with first 6 digits
        $prefix6 = substr($eanStripped, 0, 6);

        return $this->findAllManufacturersByPrefix($prefix6);
    }

    /**
     * @return array<array{manufacturer_id: string, manufacturer_name: string, manufacturer_ean_prefix: string|null}>
     */
    private function findAllManufacturersByPrefix(string $prefix): array
    {
        $query = <<<SQL
SELECT
    manufacturer.id AS manufacturer_id,
    manufacturer.name AS manufacturer_name,
    manufacturer.ean_prefix AS manufacturer_ean_prefix
FROM manufacturer
WHERE manufacturer.ean_prefix LIKE :prefix
  AND manufacturer.approved = true
SQL;

        /** @var array<array{manufacturer_id: string, manufacturer_name: string, manufacturer_ean_prefix: string|null}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'prefix' => '%' . $prefix . '%',
            ])
            ->fetchAllAssociative();

        return $rows;
    }
}
