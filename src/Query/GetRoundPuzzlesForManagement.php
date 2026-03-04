<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\RoundPuzzleForManagement;

readonly final class GetRoundPuzzlesForManagement
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<RoundPuzzleForManagement>
     */
    public function ofRound(string $roundId): array
    {
        $query = <<<SQL
SELECT
    crp.id AS round_puzzle_id,
    crp.hide_until_round_starts,
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.pieces_count,
    p.image AS puzzle_image,
    m.name AS manufacturer_name
FROM competition_round_puzzle crp
INNER JOIN puzzle p ON p.id = crp.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE crp.round_id = :roundId
ORDER BY p.name
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'roundId' => $roundId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): RoundPuzzleForManagement {
            /**
             * @var array{
             *     round_puzzle_id: string,
             *     hide_until_round_starts: bool|string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     pieces_count: int|string,
             *     puzzle_image: null|string,
             *     manufacturer_name: null|string,
             * } $row
             */

            $hideUntilRoundStarts = $row['hide_until_round_starts'];
            if (is_string($hideUntilRoundStarts)) {
                $hideUntilRoundStarts = $hideUntilRoundStarts === 't' || $hideUntilRoundStarts === '1' || $hideUntilRoundStarts === 'true';
            }

            return new RoundPuzzleForManagement(
                roundPuzzleId: $row['round_puzzle_id'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                piecesCount: (int) $row['pieces_count'],
                puzzleImage: $row['puzzle_image'],
                manufacturerName: $row['manufacturer_name'],
                hideUntilRoundStarts: $hideUntilRoundStarts,
            );
        }, $data);
    }
}
