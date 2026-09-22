<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\Ean;

/**
 * Write-side guard of multiscan linking and quick-add: every puzzle whose
 * stored code list (comma-separated, leading zeros tolerated) carries the code
 * - INCLUDING hidden puzzles, so nobody can link a code to a secret competition
 * puzzle or create a duplicate of it. Never used for display.
 */
readonly final class FindPuzzlesByExactEan
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<string>
     */
    public function ids(Ean $ean): array
    {
        $normalized = $ean->normalized();

        if ($normalized === '') {
            return [];
        }

        $query = <<<SQL
SELECT puzzle.id
FROM puzzle
WHERE puzzle.ean LIKE :pattern
  AND EXISTS (
      SELECT 1
      FROM unnest(string_to_array(replace(puzzle.ean, ' ', ''), ',')) AS part
      WHERE ltrim(part, '0') = :normalized
  )
SQL;

        /** @var list<string> $ids */
        $ids = $this->database
            ->executeQuery($query, [
                'pattern' => '%' . $normalized . '%',
                'normalized' => $normalized,
            ])
            ->fetchFirstColumn();

        return $ids;
    }
}
