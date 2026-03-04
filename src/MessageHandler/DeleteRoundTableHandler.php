<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\DeleteRoundTable;
use SpeedPuzzling\Web\Repository\RoundTableRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteRoundTableHandler
{
    public function __construct(
        private RoundTableRepository $roundTableRepository,
    ) {
    }

    public function __invoke(DeleteRoundTable $message): void
    {
        $table = $this->roundTableRepository->get($message->tableId);
        $this->roundTableRepository->delete($table);
    }
}
