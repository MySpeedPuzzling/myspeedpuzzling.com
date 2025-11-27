<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\LentPuzzleOverview;

readonly final class GetLentPuzzles
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<LentPuzzleOverview>
     */
    public function byOwnerId(string $ownerId): array
    {
        $query = <<<SQL
SELECT
    lp.id as lent_puzzle_id,
    lp.notes,
    lp.lent_at,
    p.id as puzzle_id,
    p.name as puzzle_name,
    p.alternative_name as puzzle_alternative_name,
    p.pieces_count,
    p.image,
    m.name as manufacturer_name,
    holder.id as current_holder_id,
    holder.name as current_holder_name,
    holder.avatar as current_holder_avatar
FROM lent_puzzle lp
JOIN puzzle p ON lp.puzzle_id = p.id
LEFT JOIN manufacturer m ON p.manufacturer_id = m.id
JOIN player holder ON lp.current_holder_player_id = holder.id
WHERE lp.owner_player_id = :ownerId
ORDER BY lp.lent_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, ['ownerId' => $ownerId])
            ->fetchAllAssociative();

        return array_map(static function (array $row): LentPuzzleOverview {
            /** @var array{
             *     lent_puzzle_id: string,
             *     notes: string|null,
             *     lent_at: string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: string|null,
             *     pieces_count: int,
             *     image: string|null,
             *     manufacturer_name: string|null,
             *     current_holder_id: string,
             *     current_holder_name: string,
             *     current_holder_avatar: string|null,
             * } $row
             */

            return new LentPuzzleOverview(
                lentPuzzleId: $row['lent_puzzle_id'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                puzzleAlternativeName: $row['puzzle_alternative_name'],
                piecesCount: $row['pieces_count'],
                manufacturerName: $row['manufacturer_name'],
                image: $row['image'],
                currentHolderId: $row['current_holder_id'],
                currentHolderName: $row['current_holder_name'],
                currentHolderAvatar: $row['current_holder_avatar'],
                notes: $row['notes'],
                lentAt: new DateTimeImmutable($row['lent_at']),
            );
        }, $data);
    }

    public function countByOwnerId(string $ownerId): int
    {
        $query = <<<SQL
SELECT COUNT(*) as item_count
FROM lent_puzzle
WHERE owner_player_id = :ownerId
SQL;

        $result = $this->database
            ->executeQuery($query, ['ownerId' => $ownerId])
            ->fetchOne();

        return is_numeric($result) ? (int) $result : 0;
    }

    public function isPuzzleLentByOwner(string $ownerId, string $puzzleId): bool
    {
        $query = <<<SQL
SELECT COUNT(*) as count
FROM lent_puzzle
WHERE owner_player_id = :ownerId AND puzzle_id = :puzzleId
SQL;

        $result = $this->database
            ->executeQuery($query, ['ownerId' => $ownerId, 'puzzleId' => $puzzleId])
            ->fetchOne();

        return is_numeric($result) && (int) $result > 0;
    }
}
