<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ConversationStatus;

readonly final class ConversationOverview
{
    public function __construct(
        public string $conversationId,
        public string $otherPlayerName,
        public string $otherPlayerCode,
        public string $otherPlayerId,
        public null|string $otherPlayerAvatar,
        public null|string $otherPlayerCountry,
        public null|string $lastMessagePreview,
        public null|DateTimeImmutable $lastMessageAt,
        public int $unreadCount,
        public ConversationStatus $status,
        public null|string $puzzleName = null,
        public null|string $puzzleId = null,
        public null|string $sellSwapListItemId = null,
        public null|string $puzzleImage = null,
        public null|string $listingType = null,
        public null|float $listingPrice = null,
        public bool $lastMessageSentByMe = false,
    ) {
    }
}
