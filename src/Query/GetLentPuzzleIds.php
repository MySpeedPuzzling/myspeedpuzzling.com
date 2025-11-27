<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetLentPuzzleIds
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Returns puzzle IDs that are currently lent out by the owner.
     * Used for marking lent puzzles in collection displays.
     *
     * @return array<string, string> Map of puzzleId => currentHolderName
     */
    public function byOwnerId(string $ownerId): array
    {
        $query = <<<SQL
SELECT
    lp.puzzle_id,
    lp.current_holder_name as holder_text_name,
    holder.name as holder_name
FROM lent_puzzle lp
LEFT JOIN player holder ON lp.current_holder_player_id = holder.id
WHERE lp.owner_player_id = :ownerId
SQL;

        $data = $this->database
            ->executeQuery($query, ['ownerId' => $ownerId])
            ->fetchAllAssociative();

        $result = [];
        foreach ($data as $row) {
            /** @var array{puzzle_id: string, holder_name: string|null, holder_text_name: string|null} $row */
            $result[$row['puzzle_id']] = $row['holder_name'] ?? $row['holder_text_name'] ?? '';
        }

        return $result;
    }
}
