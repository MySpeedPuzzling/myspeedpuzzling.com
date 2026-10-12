<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * "New since your last visit" on the Hub's Favorites feed (docs/features/navigation, INB-04a): when the player last
 * looked at the feed is `player.favorites_seen_at`. The first visit after a pause marks it at once (the Live action
 * fires when the pane is shown), so the next render - a return within a minute included - starts from that visit and
 * highlights nothing that was already seen. MARK_EVERY_SECONDS only spares writes: while the last mark is younger,
 * showing the feed again changes nothing. The same rule runs in the entity (authoritative), in the Live action (to
 * skip a pointless dispatch) and in assets/controllers/favorites_seen_controller.js (to skip the POST), which reads
 * the number from the page. Keeping the highlights on Back is GEST-15's instant Back (the page restored from its
 * snapshot), not this rule's job.
 */
final readonly class FavoritesSeen
{
    public const int MARK_EVERY_SECONDS = 600;

    public static function isDue(null|DateTimeImmutable $seenAt, DateTimeImmutable $now): bool
    {
        return $seenAt === null || $now->getTimestamp() - $seenAt->getTimestamp() >= self::MARK_EVERY_SECONDS;
    }
}
