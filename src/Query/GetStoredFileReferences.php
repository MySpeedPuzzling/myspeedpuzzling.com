<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Which object-storage keys the database still points at. Every column that holds
 * a storage key is checked, so a file is only ever called unreferenced when
 * nothing at all uses it - the safety net for deleting files after the fact.
 */
readonly final class GetStoredFileReferences
{
    private const int CHUNK_SIZE = 1000;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $paths
     * @return array<string, true> the given paths some row still references
     */
    public function referencedAmong(array $paths): array
    {
        $referenced = [];

        foreach (array_chunk(array_values(array_unique($paths)), self::CHUNK_SIZE) as $chunk) {
            $query = <<<SQL
SELECT avatar AS path FROM player WHERE avatar IN (:paths)
UNION SELECT finished_puzzle_photo FROM puzzle_solving_time WHERE finished_puzzle_photo IN (:paths)
UNION SELECT image FROM puzzle WHERE image IN (:paths)
UNION SELECT proposed_image FROM puzzle_change_request WHERE proposed_image IN (:paths)
UNION SELECT original_image FROM puzzle_change_request WHERE original_image IN (:paths)
UNION SELECT logo FROM manufacturer WHERE logo IN (:paths)
UNION SELECT logo FROM competition WHERE logo IN (:paths)
UNION SELECT logo FROM competition_series WHERE logo IN (:paths)
UNION SELECT logo_path FROM oauth2_client_request WHERE logo_path IN (:paths)
SQL;

            /** @var list<string> $rows */
            $rows = $this->database
                ->executeQuery($query, ['paths' => $chunk], ['paths' => ArrayParameterType::STRING])
                ->fetchFirstColumn();

            foreach ($rows as $path) {
                $referenced[$path] = true;
            }
        }

        return $referenced;
    }

    public function playerExists(string $playerId): bool
    {
        return $this->database
            ->executeQuery('SELECT 1 FROM player WHERE id = :id', ['id' => $playerId])
            ->fetchOne() !== false;
    }
}
