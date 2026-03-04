<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\RoundTable;
use SpeedPuzzling\Web\Entity\TableRow;
use SpeedPuzzling\Web\Entity\TableSpot;
use SpeedPuzzling\Web\Message\GenerateTableLayout;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\RoundTableRepository;
use SpeedPuzzling\Web\Repository\TableRowRepository;
use SpeedPuzzling\Web\Repository\TableSpotRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class GenerateTableLayoutHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private TableRowRepository $tableRowRepository,
        private RoundTableRepository $roundTableRepository,
        private TableSpotRepository $tableSpotRepository,
        private Connection $database,
    ) {
    }

    public function __invoke(GenerateTableLayout $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        // Clear existing layout
        $this->database->executeStatement(
            'DELETE FROM table_row WHERE round_id = :roundId',
            ['roundId' => $message->roundId],
        );

        $tableNumber = 1;

        for ($rowIndex = 0; $rowIndex < $message->numberOfRows; $rowIndex++) {
            $row = new TableRow(
                id: Uuid::uuid7(),
                round: $round,
                position: $rowIndex + 1,
                label: 'Row ' . ($rowIndex + 1),
            );
            $this->tableRowRepository->save($row);

            for ($tableIndex = 0; $tableIndex < $message->tablesPerRow; $tableIndex++) {
                $table = new RoundTable(
                    id: Uuid::uuid7(),
                    row: $row,
                    position: $tableIndex + 1,
                    label: 'Table ' . $tableNumber,
                );
                $this->roundTableRepository->save($table);
                $tableNumber++;

                for ($spotIndex = 0; $spotIndex < $message->spotsPerTable; $spotIndex++) {
                    $spot = new TableSpot(
                        id: Uuid::uuid7(),
                        table: $table,
                        position: $spotIndex + 1,
                    );
                    $this->tableSpotRepository->save($spot);
                }
            }
        }
    }
}
