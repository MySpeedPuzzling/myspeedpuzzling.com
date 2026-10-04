<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleTextSearch;
use SpeedPuzzling\Web\Value\Ean;

/**
 * Write-side guard of multiscan linking and quick-add: every puzzle that
 * carries the code as one of its EANs (leading zeros tolerated, the search key
 * of PuzzleTextSearch) - INCLUDING hidden puzzles, so nobody can link a code to
 * a secret competition puzzle or create a duplicate of it. Never used for display.
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
        $textSearch = PuzzleTextSearch::fromUserInput($ean->digits);
        $barcodeCondition = $textSearch->barcodeCondition('puzzle');

        if ($barcodeCondition === null) {
            return [];
        }

        $query = <<<SQL
SELECT puzzle.id
FROM puzzle
WHERE {$barcodeCondition}
SQL;

        /** @var list<string> $ids */
        $ids = $this->database
            ->executeQuery($query, $textSearch->parameters())
            ->fetchFirstColumn();

        return $ids;
    }
}
