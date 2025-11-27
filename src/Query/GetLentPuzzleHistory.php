<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\LentPuzzleTransferOverview;
use SpeedPuzzling\Web\Value\TransferType;

readonly final class GetLentPuzzleHistory
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<LentPuzzleTransferOverview>
     */
    public function byLentPuzzleId(string $lentPuzzleId): array
    {
        $query = <<<SQL
SELECT
    lpt.id as transfer_id,
    lpt.transferred_at,
    lpt.transfer_type,
    from_player.id as from_player_id,
    from_player.name as from_player_name,
    to_player.id as to_player_id,
    to_player.name as to_player_name
FROM lent_puzzle_transfer lpt
LEFT JOIN player from_player ON lpt.from_player_id = from_player.id
JOIN player to_player ON lpt.to_player_id = to_player.id
WHERE lpt.lent_puzzle_id = :lentPuzzleId
ORDER BY lpt.transferred_at ASC
SQL;

        $data = $this->database
            ->executeQuery($query, ['lentPuzzleId' => $lentPuzzleId])
            ->fetchAllAssociative();

        return array_map(static function (array $row): LentPuzzleTransferOverview {
            /** @var array{
             *     transfer_id: string,
             *     transferred_at: string,
             *     transfer_type: string,
             *     from_player_id: string|null,
             *     from_player_name: string|null,
             *     to_player_id: string,
             *     to_player_name: string,
             * } $row
             */

            return new LentPuzzleTransferOverview(
                transferId: $row['transfer_id'],
                fromPlayerId: $row['from_player_id'],
                fromPlayerName: $row['from_player_name'],
                toPlayerId: $row['to_player_id'],
                toPlayerName: $row['to_player_name'],
                transferredAt: new DateTimeImmutable($row['transferred_at']),
                transferType: TransferType::from($row['transfer_type']),
            );
        }, $data);
    }
}
