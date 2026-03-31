<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\LendBorrowHistoryItem;
use SpeedPuzzling\Web\Value\TransferType;

readonly final class GetLendBorrowHistory
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<LendBorrowHistoryItem>
     */
    public function byPlayerId(string $playerId): array
    {
        $query = <<<SQL
SELECT
    lpt.id as transfer_id,
    lpt.transferred_at,
    lpt.transfer_type,
    lpt.from_player_name as from_text_name,
    lpt.to_player_name as to_text_name,
    lpt.owner_name as owner_text_name,
    from_player.id as from_player_id,
    COALESCE(from_player.name, from_player.code, lpt.from_player_name) as from_player_name,
    to_player.id as to_player_id,
    COALESCE(to_player.name, to_player.code, lpt.to_player_name) as to_player_name,
    owner_player.id as owner_player_id,
    COALESCE(owner_player.name, owner_player.code, lpt.owner_name) as owner_player_name,
    p.id as puzzle_id,
    p.name as puzzle_name,
    p.alternative_name as puzzle_alternative_name,
    p.pieces_count,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > NOW() THEN NULL ELSE p.image END AS image,
    m.name as manufacturer_name,
    lpt.lent_puzzle_id IS NOT NULL as is_active
FROM lent_puzzle_transfer lpt
LEFT JOIN puzzle p ON lpt.puzzle_id = p.id
LEFT JOIN manufacturer m ON p.manufacturer_id = m.id
LEFT JOIN player from_player ON lpt.from_player_id = from_player.id
LEFT JOIN player to_player ON lpt.to_player_id = to_player.id
LEFT JOIN player owner_player ON lpt.owner_player_id = owner_player.id
WHERE lpt.from_player_id = :playerId
   OR lpt.to_player_id = :playerId
   OR lpt.owner_player_id = :playerId
ORDER BY lpt.transferred_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchAllAssociative();

        return array_map(static function (array $row): LendBorrowHistoryItem {
            /** @var array{
             *     transfer_id: string,
             *     transferred_at: string,
             *     transfer_type: string,
             *     from_text_name: string|null,
             *     to_text_name: string|null,
             *     owner_text_name: string|null,
             *     from_player_id: string|null,
             *     from_player_name: string|null,
             *     to_player_id: string|null,
             *     to_player_name: string|null,
             *     owner_player_id: string|null,
             *     owner_player_name: string|null,
             *     puzzle_id: string|null,
             *     puzzle_name: string|null,
             *     puzzle_alternative_name: string|null,
             *     pieces_count: int|null,
             *     image: string|null,
             *     manufacturer_name: string|null,
             *     is_active: bool,
             * } $row
             */

            return new LendBorrowHistoryItem(
                transferId: $row['transfer_id'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                puzzleAlternativeName: $row['puzzle_alternative_name'],
                piecesCount: $row['pieces_count'] !== null ? (int) $row['pieces_count'] : null,
                manufacturerName: $row['manufacturer_name'],
                image: $row['image'],
                transferType: TransferType::from($row['transfer_type']),
                fromPlayerId: $row['from_player_id'],
                fromPlayerName: $row['from_player_name'] ?? $row['from_text_name'],
                toPlayerId: $row['to_player_id'],
                toPlayerName: $row['to_player_name'] ?? $row['to_text_name'],
                ownerPlayerId: $row['owner_player_id'],
                ownerPlayerName: $row['owner_player_name'] ?? $row['owner_text_name'],
                transferredAt: new DateTimeImmutable($row['transferred_at']),
                isActive: (bool) $row['is_active'],
            );
        }, $data);
    }
}
