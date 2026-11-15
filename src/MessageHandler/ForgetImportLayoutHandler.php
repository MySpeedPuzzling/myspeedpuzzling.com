<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\ImportLayoutNotFound;
use SpeedPuzzling\Web\Message\ForgetImportLayout;
use SpeedPuzzling\Web\Repository\ImportLayoutRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ForgetImportLayoutHandler
{
    public function __construct(
        private ImportLayoutRepository $importLayoutRepository,
    ) {
    }

    /**
     * @throws ImportLayoutNotFound
     */
    public function __invoke(ForgetImportLayout $message): void
    {
        $this->importLayoutRepository->delete($this->importLayoutRepository->getOwnedBy($message->layoutId, $message->playerId));
    }
}
