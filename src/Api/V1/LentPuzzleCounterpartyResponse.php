<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

/**
 * The other side of a lend/borrow entry - the holder of a lent puzzle, the
 * owner of a borrowed one. Only what the website's list shows: the player's
 * id when they are a registered player (null for a person entered by name
 * only, or for a returned puzzle with no holder) and the display name (the
 * registered player's name, the free-text name, or "" for a returned puzzle).
 */
final class LentPuzzleCounterpartyResponse
{
    public function __construct(
        public null|string $player_id,
        public string $name,
    ) {
    }
}
