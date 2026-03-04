<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\TableSpot;
use SpeedPuzzling\Web\Message\AddTableSpot;
use SpeedPuzzling\Web\Repository\RoundTableRepository;
use SpeedPuzzling\Web\Repository\TableSpotRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddTableSpotHandler
{
    public function __construct(
        private RoundTableRepository $roundTableRepository,
        private TableSpotRepository $tableSpotRepository,
        private Connection $database,
    ) {
    }

    public function __invoke(AddTableSpot $message): void
    {
        $table = $this->roundTableRepository->get($message->tableId);

        /** @var int|string|false $result */
        $result = $this->database->fetchOne(
            'SELECT COALESCE(MAX(position), 0) FROM table_spot WHERE table_id = :tableId',
            ['tableId' => $message->tableId],
        );
        $maxPosition = (int) $result;

        $spot = new TableSpot(
            id: $message->spotId,
            table: $table,
            position: $maxPosition + 1,
        );

        $this->tableSpotRepository->save($spot);
    }
}
