<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\DeleteTableRow;
use SpeedPuzzling\Web\Repository\TableRowRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteTableRowHandler
{
    public function __construct(
        private TableRowRepository $tableRowRepository,
    ) {
    }

    public function __invoke(DeleteTableRow $message): void
    {
        $row = $this->tableRowRepository->get($message->rowId);
        $this->tableRowRepository->delete($row);
    }
}
