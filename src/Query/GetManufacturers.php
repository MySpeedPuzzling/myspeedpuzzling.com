<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ManufacturerOverview;

readonly final class GetManufacturers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<ManufacturerOverview>
     */
    public function onlyApprovedOrAddedByPlayer(null|string $playerId = null, null|string $extraManufacturerId = null): array
    {
        $query = <<<SQL
SELECT
    manufacturer.id AS manufacturer_id,
    manufacturer.name AS manufacturer_name,
    manufacturer.approved AS manufacturer_approved,
    manufacturer.logo AS manufacturer_logo,
    manufacturer.ean_prefix AS manufacturer_ean_prefix,
    COUNT(puzzle.id) AS puzzles_count
FROM manufacturer
LEFT JOIN puzzle ON puzzle.manufacturer_id = manufacturer.id
WHERE (manufacturer.approved = true OR manufacturer.added_by_user_id = :playerId OR manufacturer.id = :extraManufacturerId)
GROUP BY manufacturer.id
ORDER BY COUNT(puzzle.id) DESC, manufacturer.name ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'extraManufacturerId' => $extraManufacturerId,
            ])
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
