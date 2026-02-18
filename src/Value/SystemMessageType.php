<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum SystemMessageType: string
{
    case ListingReserved = 'listing_reserved';
    case ListingReservationRemoved = 'listing_reservation_removed';
    case ListingSold = 'listing_sold';

    public static function resolveTranslationKey(self $type, null|string $targetPlayerId, null|string $viewerId, null|string $otherParticipantId = null): string
    {
        return match ($type) {
            self::ListingReserved => match (true) {
                $targetPlayerId !== null && $targetPlayerId === $viewerId => 'messaging.system.listing_reserved_for_you',
                $targetPlayerId !== null && $otherParticipantId !== null && $targetPlayerId === $otherParticipantId => 'messaging.system.listing_reserved_for_this_puzzler',
                $targetPlayerId !== null => 'messaging.system.listing_reserved_for_someone_else',
                default => 'messaging.system.listing_reserved',
            },
            self::ListingReservationRemoved => 'messaging.system.listing_reservation_removed',
            self::ListingSold => match (true) {
                $targetPlayerId !== null && $targetPlayerId === $viewerId => 'messaging.system.listing_sold_to_you',
                $targetPlayerId !== null && $otherParticipantId !== null && $targetPlayerId === $otherParticipantId => 'messaging.system.listing_sold_to_this_puzzler',
                $targetPlayerId !== null => 'messaging.system.listing_sold_to_someone_else',
                default => 'messaging.system.listing_sold',
            },
        };
    }
}
