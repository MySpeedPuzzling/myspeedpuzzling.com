<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Which puzzle each id is now: the puzzle itself while it exists, the puzzle it was merged into when a merge deleted
 * it (puzzle_redirect - kept one hop deep, a later merge rewrites the older redirects:
 * PuzzleRedirectRepository::redirectToNewSurvivor()), null when it is gone without a merge.
 */
readonly final class GetCurrentPuzzleIds
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param array<string> $puzzleIds
     *
     * @return array<string, null|string> Lower-case id => the puzzle it is now (lower case), null = gone
     */
    public function of(array $puzzleIds): array
    {
        $puzzleIds = array_values(array_unique(array_map(strtolower(...), $puzzleIds)));
        $current = array_fill_keys($puzzleIds, null);
        $validIds = array_values(array_filter($puzzleIds, static fn (string $id): bool => Uuid::isValid($id)));

        if ($validIds === []) {
            return $current;
        }

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT p.id AS puzzle_id, p.id AS current_id
FROM puzzle p
WHERE p.id IN (:ids)
UNION ALL
SELECT r.old_puzzle_id, r.survivor_puzzle_id
FROM puzzle_redirect r
JOIN puzzle survivor ON survivor.id = r.survivor_puzzle_id
WHERE r.old_puzzle_id IN (:ids)
    AND NOT EXISTS (SELECT 1 FROM puzzle old WHERE old.id = r.old_puzzle_id)
SQL,
            ['ids' => $validIds],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        foreach ($rows as $row) {
            if (is_string($row['puzzle_id']) && is_string($row['current_id'])) {
                $current[strtolower($row['puzzle_id'])] = strtolower($row['current_id']);
            }
        }

        return $current;
    }
}
