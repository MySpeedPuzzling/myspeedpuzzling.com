<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\ImportLayout;
use SpeedPuzzling\Web\Exceptions\ImportLayoutNotFound;
use SpeedPuzzling\Web\Message\RenameImportLayout;
use SpeedPuzzling\Web\Repository\ImportLayoutRepository;
use SpeedPuzzling\Web\Value\DataImport\ImportText;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A name is a short line of text: cleaned like any text of an import, cut at ImportLayout::MAX_NAME_LENGTH. An
 * empty one changes nothing. Another player's layout is "not found".
 */
#[AsMessageHandler]
readonly final class RenameImportLayoutHandler
{
    public function __construct(
        private ImportLayoutRepository $importLayoutRepository,
    ) {
    }

    /**
     * @throws ImportLayoutNotFound
     */
    public function __invoke(RenameImportLayout $message): void
    {
        $layout = $this->importLayoutRepository->getOwnedBy($message->layoutId, $message->playerId);
        $name = trim((string) preg_replace('/\s+/u', ' ', ImportText::clean($message->name)));

        if ($name !== '') {
            $layout->rename(mb_substr($name, 0, ImportLayout::MAX_NAME_LENGTH));
        }
    }
}
