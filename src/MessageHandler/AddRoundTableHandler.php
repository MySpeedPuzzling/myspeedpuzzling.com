<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\RoundTable;
use SpeedPuzzling\Web\Entity\TableSpot;
use SpeedPuzzling\Web\Message\AddRoundTable;
use SpeedPuzzling\Web\Repository\RoundTableRepository;
use SpeedPuzzling\Web\Repository\TableRowRepository;
use SpeedPuzzling\Web\Repository\TableSpotRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddRoundTableHandler
{
    public function __construct(
        private TableRowRepository $tableRowRepository,
        private RoundTableRepository $roundTableRepository,
        private TableSpotRepository $tableSpotRepository,
        private Connection $database,
    ) {
    }

    public function __invoke(AddRoundTable $message): void
    {
        $row = $this->tableRowRepository->get($message->rowId);

        /** @var int|string|false $maxPositionResult */
        $maxPositionResult = $this->database->fetchOne(
            'SELECT COALESCE(MAX(position), 0) FROM round_table WHERE row_id = :rowId',
            ['rowId' => $message->rowId],
        );
        $maxPosition = (int) $maxPositionResult;

        /** @var int|string|false $totalResult */
        $totalResult = $this->database->fetchOne(
            'SELECT COUNT(*) FROM round_table rt INNER JOIN table_row tr ON rt.row_id = tr.id WHERE tr.round_id = :roundId',
            ['roundId' => $row->round->id->toString()],
        );
        $totalTablesInRound = (int) $totalResult;

        $table = new RoundTable(
            id: $message->tableId,
            row: $row,
            position: $maxPosition + 1,
            label: 'Table ' . ($totalTablesInRound + 1),
        );

        $this->roundTableRepository->save($table);

        $spot = new TableSpot(
            id: Uuid::uuid7(),
            table: $table,
            position: 1,
        );

        $this->tableSpotRepository->save($spot);
    }
}
