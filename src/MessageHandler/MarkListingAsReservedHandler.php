<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\Message\MarkListingAsReserved;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Services\SystemMessageSender;
use SpeedPuzzling\Web\Value\SystemMessageType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class MarkListingAsReservedHandler
{
    public function __construct(
        private SellSwapListItemRepository $sellSwapListItemRepository,
        private PlayerRepository $playerRepository,
        private SystemMessageSender $systemMessageSender,
    ) {
    }

    /**
     * @throws SellSwapListItemNotFound
     */
    public function __invoke(MarkListingAsReserved $message): void
    {
        $item = $this->sellSwapListItemRepository->get($message->sellSwapListItemId);

        if ($item->player->id->toString() !== $message->playerId) {
            throw new SellSwapListItemNotFound();
        }

        /** @var UuidInterface|null $reservedForPlayerId */
        $reservedForPlayerId = null;

        // Direct player ID from conversation "to this puzzler" button
        if ($message->reservedForPlayerId !== null) {
            $reservedForPlayerId = Uuid::fromString($message->reservedForPlayerId);
        } elseif ($message->reservedForInput !== null && $message->reservedForInput !== '') {
            $reservedForPlayerId = $this->resolvePlayerFromInput($message->reservedForInput);
        }

        $item->markAsReserved($reservedForPlayerId);

        $this->systemMessageSender->sendToAllConversations(
            $item,
            SystemMessageType::ListingReserved,
            $reservedForPlayerId,
        );
    }

    private function resolvePlayerFromInput(string $input): null|UuidInterface
    {
        $isPlayerCode = str_starts_with($input, '#');
        $input = trim($input, "# \t\n\r\0");

        if ($input === '' || !$isPlayerCode) {
            return null;
        }

        try {
            $player = $this->playerRepository->getByCode($input);

            return $player->id;
        } catch (PlayerNotFound) {
            return null;
        }
    }
}
