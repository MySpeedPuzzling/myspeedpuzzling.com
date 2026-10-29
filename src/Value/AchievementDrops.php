<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * Every achievement drop - a few new achievements a month (Jan 2026-10-29), each announced as news: the "New" tag on
 * the shelf and in the catalogue for NEW_FOR_DAYS, the digest's block in the week of the drop (docs/features/badges.md
 * "Achievement drops"). The award itself stays retroactive and dated from history, so it is never news by date.
 *
 * A drop = one entry here, added with its achievements; releasedAt = the deploy moment (UTC). Achievements of the
 * 2026-10-12 launch are in no drop.
 */
final class AchievementDrops
{
    public const int NEW_FOR_DAYS = 30;

    /**
     * @return list<AchievementDrop> oldest first
     */
    public static function all(): array
    {
        return [
            new AchievementDrop(new DateTimeImmutable('2026-10-29 14:20:00 UTC'), [
                BadgeType::MuscleMemory,
                BadgeType::MuscleMemoryUnboxed,
                BadgeType::NightOwl,
                BadgeType::Competitor,
                BadgeType::OnlineCompetitor,
            ]),
        ];
    }

    public static function isNew(BadgeType $type, DateTimeImmutable $now): bool
    {
        foreach (self::all() as $drop) {
            if ($drop->contains($type) && self::isFresh($drop, $now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The newest drop released in [from, to) - the digest of that period announces it
     */
    public static function releasedBetween(DateTimeImmutable $from, DateTimeImmutable $to): null|AchievementDrop
    {
        $found = null;

        foreach (self::all() as $drop) {
            if ($drop->releasedAt >= $from && $drop->releasedAt < $to) {
                $found = $drop;
            }
        }

        return $found;
    }

    private static function isFresh(AchievementDrop $drop, DateTimeImmutable $now): bool
    {
        return $drop->releasedAt <= $now
            && $now < $drop->releasedAt->modify('+' . self::NEW_FOR_DAYS . ' days');
    }
}
