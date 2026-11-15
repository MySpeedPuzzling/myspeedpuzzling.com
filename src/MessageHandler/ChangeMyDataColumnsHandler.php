<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ChangeMyDataColumns;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\MyDataColumns;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ChangeMyDataColumnsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(ChangeMyDataColumns $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        $player->changeMyDataColumns(
            MyDataColumns::fromStored($player->myDataColumns)->withChoice($message->tab, $message->offeredColumns, $message->shownColumns),
        );
    }
}
