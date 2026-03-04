<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\TableRow;
use SpeedPuzzling\Web\Message\AddTableRow;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\TableRowRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddTableRowHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private TableRowRepository $tableRowRepository,
        private Connection $database,
    ) {
    }

    public function __invoke(AddTableRow $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        /** @var int|string|false $result */
        $result = $this->database->fetchOne(
            'SELECT COALESCE(MAX(position), 0) FROM table_row WHERE round_id = :roundId',
            ['roundId' => $message->roundId],
        );
        $maxPosition = (int) $result;

        $row = new TableRow(
            id: $message->rowId,
            round: $round,
            position: $maxPosition + 1,
            label: 'Row ' . ($maxPosition + 1),
        );

        $this->tableRowRepository->save($row);
    }
}
