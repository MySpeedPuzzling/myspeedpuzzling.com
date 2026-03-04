<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\ClearTableLayout;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ClearTableLayoutHandler
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function __invoke(ClearTableLayout $message): void
    {
        $this->database->executeStatement(
            'DELETE FROM table_row WHERE round_id = :roundId',
            ['roundId' => $message->roundId],
        );
    }
}
