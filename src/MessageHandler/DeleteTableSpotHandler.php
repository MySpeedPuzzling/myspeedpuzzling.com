<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\DeleteTableSpot;
use SpeedPuzzling\Web\Repository\TableSpotRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteTableSpotHandler
{
    public function __construct(
        private TableSpotRepository $tableSpotRepository,
    ) {
    }

    public function __invoke(DeleteTableSpot $message): void
    {
        $spot = $this->tableSpotRepository->get($message->spotId);
        $this->tableSpotRepository->delete($spot);
    }
}
