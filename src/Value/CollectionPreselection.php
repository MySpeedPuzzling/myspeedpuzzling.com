<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Results\PlayerProfile;

/**
 * Which collection a collection picker starts with (docs/features/collections/default-collection.md) - the one rule of
 * every "add to a collection" picker. Precedence: a URL preset and a submitted value (even an empty one) are the
 * caller's - they win because the caller applies this only to an empty field before handleRequest(). Then the member's
 * default collection, then the system collection, then the only choice there is.
 *
 * The stored default counts only while it is among the choices built for this request: the player's own collection,
 * a membership still active, the puzzle not in it already. Never the foreign key alone.
 */
final class CollectionPreselection
{
    /**
     * @param array<string, null|string> $choices label => collection id (or Collection::SYSTEM_ID), as the form gets them
     */
    public static function pick(PlayerProfile $player, array $choices): null|string
    {
        $ids = array_values(array_filter($choices, is_string(...)));

        $default = self::defaultOf($player);

        if (in_array($default, $ids, true)) {
            return $default;
        }

        if (in_array(Collection::SYSTEM_ID, $ids, true)) {
            return Collection::SYSTEM_ID;
        }

        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * The collection the player chose as default, or the system collection (also for a lapsed membership - custom
     * collections are members-only)
     */
    public static function defaultOf(PlayerProfile $player): string
    {
        if ($player->activeMembership === false || $player->defaultCollectionId === null) {
            return Collection::SYSTEM_ID;
        }

        return $player->defaultCollectionId;
    }
}
