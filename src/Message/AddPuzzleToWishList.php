<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class AddPuzzleToWishList
{
    public function __construct(
        public string $playerId,
        public string $puzzleId,
        public bool $removeOnCollectionAdd = false,
    ) {
    }
}
