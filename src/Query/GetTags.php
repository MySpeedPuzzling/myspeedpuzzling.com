<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Results\PuzzleTag;

readonly final class GetTags
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PuzzleNotFound
     * @return array<PuzzleTag>
     */
    public function forPuzzle(string $puzzleId): array
    {
        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound;
        }

        $query = <<<SQL
SELECT
  tag.id AS tag_id,
  tag.name
FROM tag
LEFT JOIN tag_puzzle ON tag.id = tag_puzzle.tag_id
WHERE tag_puzzle.puzzle_id = :puzzleId
SQL;


        $data = $this->database
            ->executeQuery($query, [
                'puzzleId' => $puzzleId,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): PuzzleTag {
            /**
             * @var array{
             *     tag_id: string,
             *     name: string,
             * } $row
             */

            return PuzzleTag::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<string, array<PuzzleTag>>
     */
    public function allGroupedPerPuzzle(): array
    {
        $query = <<<SQL
SELECT
  tag.id AS tag_id,
  tag.name,
  puzzle_id
FROM tag
LEFT JOIN tag_puzzle ON tag.id = tag_puzzle.tag_id
SQL;

        $data = [];
        $results = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        foreach ($results as $row) {
            /**
             * @var array{
             *     puzzle_id: string,
             *     tag_id: string,
             *     name: string,
             * } $row
             */

            $data[$row['puzzle_id']][] = PuzzleTag::fromDatabaseRow($row);
        }

        return $data;
    }
}
