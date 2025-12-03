<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\BorrowedPuzzleOverview;
use SpeedPuzzling\Web\Results\UnsolvedPuzzleItem;

readonly final class GetBorrowedPuzzles
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<BorrowedPuzzleOverview>
     */
    public function byHolderId(string $holderId): array
    {
        $query = <<<SQL
SELECT
    lp.id as lent_puzzle_id,
    lp.notes,
    lp.lent_at,
    lp.owner_name as owner_text_name,
    p.id as puzzle_id,
    p.name as puzzle_name,
    p.alternative_name as puzzle_alternative_name,
    p.pieces_count,
    p.image,
    m.name as manufacturer_name,
    owner.id as owner_id,
    owner.name as owner_name,
    owner.avatar as owner_avatar
FROM lent_puzzle lp
JOIN puzzle p ON lp.puzzle_id = p.id
LEFT JOIN manufacturer m ON p.manufacturer_id = m.id
LEFT JOIN player owner ON lp.owner_player_id = owner.id
WHERE lp.current_holder_player_id = :holderId
AND (lp.owner_player_id IS NULL OR lp.owner_player_id != :holderId)
ORDER BY lp.lent_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, ['holderId' => $holderId])
            ->fetchAllAssociative();

        return array_map(static function (array $row): BorrowedPuzzleOverview {
            /** @var array{
             *     lent_puzzle_id: string,
             *     notes: string|null,
             *     lent_at: string,
             *     owner_text_name: string|null,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: string|null,
             *     pieces_count: int,
             *     image: string|null,
             *     manufacturer_name: string|null,
             *     owner_id: string|null,
             *     owner_name: string|null,
             *     owner_avatar: string|null,
             * } $row
             */

            return new BorrowedPuzzleOverview(
                lentPuzzleId: $row['lent_puzzle_id'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                puzzleAlternativeName: $row['puzzle_alternative_name'],
                piecesCount: $row['pieces_count'],
                manufacturerName: $row['manufacturer_name'],
                image: $row['image'],
                ownerId: $row['owner_id'],
                ownerName: $row['owner_name'] ?? $row['owner_text_name'] ?? '',
                ownerAvatar: $row['owner_avatar'],
                notes: $row['notes'],
                lentAt: new DateTimeImmutable($row['lent_at']),
            );
        }, $data);
    }

    public function countByHolderId(string $holderId): int
    {
        $query = <<<SQL
SELECT COUNT(*) as item_count
FROM lent_puzzle
WHERE current_holder_player_id = :holderId
AND (owner_player_id IS NULL OR owner_player_id != :holderId)
SQL;

        $result = $this->database
            ->executeQuery($query, ['holderId' => $holderId])
            ->fetchOne();

        return is_numeric($result) ? (int) $result : 0;
    }

    public function isPuzzleBorrowedByHolder(string $holderId, string $puzzleId): bool
    {
        $query = <<<SQL
SELECT COUNT(*) as count
FROM lent_puzzle
WHERE current_holder_player_id = :holderId
AND puzzle_id = :puzzleId
AND (owner_player_id IS NULL OR owner_player_id != :holderId)
SQL;

        $result = $this->database
            ->executeQuery($query, ['holderId' => $holderId, 'puzzleId' => $puzzleId])
            ->fetchOne();

        return is_numeric($result) && (int) $result > 0;
    }

    /**
     * @return array<UnsolvedPuzzleItem>
     */
    public function unsolvedByHolderId(string $holderId): array
    {
        $query = <<<SQL
SELECT
    p.id as puzzle_id,
    p.name as puzzle_name,
    p.alternative_name as puzzle_alternative_name,
    p.identification_number as puzzle_identification_number,
    p.ean,
    p.pieces_count,
    p.image,
    m.name as manufacturer_name,
    lp.lent_at as added_at,
    lp.owner_name as owner_text_name,
    owner.id as owner_id,
    owner.name as owner_name
FROM lent_puzzle lp
JOIN puzzle p ON lp.puzzle_id = p.id
LEFT JOIN manufacturer m ON p.manufacturer_id = m.id
LEFT JOIN player owner ON lp.owner_player_id = owner.id
LEFT JOIN puzzle_solving_time pst ON (
    pst.player_id = :holderId
    AND pst.puzzle_id = p.id
)
WHERE lp.current_holder_player_id = :holderId
AND (lp.owner_player_id IS NULL OR lp.owner_player_id != :holderId)
AND pst.id IS NULL
ORDER BY lp.lent_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, ['holderId' => $holderId])
            ->fetchAllAssociative();

        return array_map(static function (array $row): UnsolvedPuzzleItem {
            /** @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: string|null,
             *     puzzle_identification_number: string|null,
             *     ean: string|null,
             *     pieces_count: int,
             *     image: string|null,
             *     manufacturer_name: string|null,
             *     added_at: string,
             *     owner_text_name: string|null,
             *     owner_id: string|null,
             *     owner_name: string|null,
             * } $row
             */

            return new UnsolvedPuzzleItem(
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                puzzleAlternativeName: $row['puzzle_alternative_name'],
                puzzleIdentificationNumber: $row['puzzle_identification_number'],
                ean: $row['ean'],
                piecesCount: $row['pieces_count'],
                manufacturerName: $row['manufacturer_name'],
                image: $row['image'],
                addedAt: new DateTimeImmutable($row['added_at']),
                isBorrowed: true,
                borrowedFromPlayerId: $row['owner_id'],
                borrowedFromPlayerName: $row['owner_name'] ?? $row['owner_text_name'],
            );
        }, $data);
    }

    public function countUnsolvedByHolderId(string $holderId): int
    {
        $query = <<<SQL
SELECT COUNT(*) as item_count
FROM lent_puzzle lp
JOIN puzzle p ON lp.puzzle_id = p.id
LEFT JOIN puzzle_solving_time pst ON (
    pst.player_id = :holderId
    AND pst.puzzle_id = p.id
)
WHERE lp.current_holder_player_id = :holderId
AND (lp.owner_player_id IS NULL OR lp.owner_player_id != :holderId)
AND pst.id IS NULL
SQL;

        $result = $this->database
            ->executeQuery($query, ['holderId' => $holderId])
            ->fetchOne();

        return is_numeric($result) ? (int) $result : 0;
    }
}
