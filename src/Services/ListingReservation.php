<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Value\SystemMessageType;

/**
 * Reserving a listing and everything that comes with it: the "reserved" system message in every conversation about
 * the listing (+ the Mercure pushes SystemMessageSender does). The one way to reserve - MarkListingAsReservedHandler and
 * the chat's "Bring it and reserve it" (BringListingToEventHandler) share it.
 */
readonly final class ListingReservation
{
    public function __construct(
        private SystemMessageSender $systemMessageSender,
    ) {
    }

    public function reserve(SellSwapListItem $item, null|UuidInterface $reservedForPlayerId): void
    {
        $item->markAsReserved($reservedForPlayerId);

        $this->systemMessageSender->sendToAllConversations(
            $item,
            SystemMessageType::ListingReserved,
            $reservedForPlayerId,
        );
    }
}
