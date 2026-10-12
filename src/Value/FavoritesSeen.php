<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * "New since your last visit" on the Hub's Favorites feed (docs/features/navigation, INB-04a): when the player last
 * looked at the feed is `player.favorites_seen_at`, and it moves at most once per MARK_EVERY_SECONDS - so coming back
 * a minute later still shows what was new a minute ago. The same rule runs in the entity (authoritative), in the
 * Live action (to skip a pointless dispatch) and in assets/controllers/favorites_seen_controller.js (to skip the POST),
 * which reads the number from the page.
 */
final readonly class FavoritesSeen
{
    public const int MARK_EVERY_SECONDS = 600;

    public static function isDue(null|DateTimeImmutable $seenAt, DateTimeImmutable $now): bool
    {
        return $seenAt === null || $now->getTimestamp() - $seenAt->getTimestamp() >= self::MARK_EVERY_SECONDS;
    }
}
